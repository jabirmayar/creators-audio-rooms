<?php

namespace daacreators\CreatorsAudioRooms\Services;

use daacreators\CreatorsAudioRooms\Contracts\Notifier;
use daacreators\CreatorsAudioRooms\Contracts\ParticipantResolver;
use daacreators\CreatorsAudioRooms\Contracts\TenantResolver;
use daacreators\CreatorsAudioRooms\Enums\MemberState;
use daacreators\CreatorsAudioRooms\Enums\RoomAction;
use daacreators\CreatorsAudioRooms\Enums\RoomRole;
use daacreators\CreatorsAudioRooms\Enums\RoomStatus;
use daacreators\CreatorsAudioRooms\Events;
use daacreators\CreatorsAudioRooms\Exceptions\RoomActionDenied;
use daacreators\CreatorsAudioRooms\Models\Ban;
use daacreators\CreatorsAudioRooms\Models\Member;
use daacreators\CreatorsAudioRooms\Models\ModerationLogEntry;
use daacreators\CreatorsAudioRooms\Models\Room;
use daacreators\CreatorsAudioRooms\Signaling\SessionToken;
use daacreators\CreatorsAudioRooms\Signaling\SignalStore;
use daacreators\CreatorsAudioRooms\Support\ParticipantIdentity;
use daacreators\CreatorsAudioRooms\Support\Tables;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * All room state changes. Each one runs in a transaction with the room row
 * locked, re-checks permissions and limits inside the lock, bumps the room
 * version (so clients refresh) and fires an event.
 */
class RoomManager
{
    public function __construct(
        private readonly RoomAccess $access,
        private readonly SignalStore $signals,
        private readonly TenantResolver $tenants,
        private readonly Notifier $notifier,
    ) {}

    // ─── Lifecycle ───────────────────────────────────────────────────────

    /**
     * @param  array{title: string, description?: ?string, audience_type?: ?string, audience_ref?: ?string}  $data
     */
    public function create(ParticipantIdentity $host, array $data): Room
    {
        $limits = (array) config('creators-audio-rooms.limits');

        // Unsaved draft so the host app can authorize against the intended audience.
        $draft = new Room([
            'tenant_key' => $this->tenants->tenantFor($host),
            'audience_type' => $data['audience_type'] ?? null,
            'audience_ref' => isset($data['audience_ref']) ? (string) $data['audience_ref'] : null,
            'title' => trim((string) $data['title']),
            'description' => isset($data['description']) ? trim((string) $data['description']) ?: null : null,
            'status' => RoomStatus::Scheduled,
            'created_by_key' => $host->key,
            'max_hosts' => (int) ($limits['max_hosts'] ?? 2),
            'max_speakers' => (int) ($limits['max_speakers'] ?? 6),
            'max_listeners' => (int) ($limits['max_listeners'] ?? 30),
        ]);

        $this->access->ensureCanCreate($host, $draft);

        $openRooms = Room::query()->open()->where('created_by_key', $host->key)->count();
        if ($openRooms >= (int) ($limits['max_open_rooms_per_host'] ?? 1)) {
            throw RoomActionDenied::because('too_many_rooms', 409);
        }

        $room = DB::connection(Tables::connection())->transaction(function () use ($draft, $host) {
            $draft->save();
            $draft->members()->create([
                'participant_key' => $host->key,
                'display_name' => $host->displayName,
                'avatar_url' => $host->avatarUrl,
                'profile_url' => $host->profileUrl,
                'role' => RoomRole::Host,
                // The creator holds the host role but is not in the room until they join.
                'state' => MemberState::Left,
            ]);

            return $draft;
        });

        Events\RoomCreated::dispatch($room, $host->key);
        $this->log('info', 'room created', $room, $host->key);

        return $room;
    }

    public function start(ParticipantIdentity $who, Room $room): Room
    {
        $room = $this->locked($room, function (Room $room) use ($who) {
            $member = $this->access->member($who, $room);
            if (! $member?->role->isHost() || ! $this->access->canHost($who, $room)) {
                throw RoomActionDenied::because('not_allowed');
            }
            if ($room->isEnded()) {
                throw RoomActionDenied::because('room_ended', 409);
            }
            if ($room->isLive()) {
                return $room;
            }

            $room->forceFill(['status' => RoomStatus::Live, 'started_at' => now()]);
            $this->touch($room);

            return $room;
        });

        Events\RoomStarted::dispatch($room, $who->key);
        $this->safely(fn () => $this->notifier->roomStarted($room), 'notifier.roomStarted', $room);
        $this->log('info', 'room started', $room, $who->key);

        return $room;
    }

    public function end(ParticipantIdentity $who, Room $room): Room
    {
        $this->access->ensureModerator($who, $room, RoomAction::End);

        return $this->close($room, 'host', $who->key);
    }

    /** End a room for a system reason (host absent, expired). */
    public function close(Room $room, string $reason, ?string $byKey = null): Room
    {
        $wasEnded = false;
        $room = $this->locked($room, function (Room $room) use ($reason, $byKey, &$wasEnded) {
            if ($room->isEnded()) {
                $wasEnded = true;

                return $room;
            }

            $room->forceFill(['status' => RoomStatus::Ended, 'ended_at' => now(), 'end_reason' => $reason]);
            $room->members()->where('state', MemberState::Joined->value)->update([
                'state' => MemberState::Left->value,
                'hand_raised_at' => null,
                'connection_id' => null,
                'left_at' => now(),
            ]);
            $this->touch($room);
            $this->moderationLog($room, $byKey ?? 'system', null, 'end', ['reason' => $reason]);

            return $room;
        });

        if (! $wasEnded) {
            Events\RoomEnded::dispatch($room, $reason, $byKey);
            $this->log('info', 'room ended', $room, $byKey, ['reason' => $reason]);
        }

        return $room;
    }

    // ─── Joining & presence ──────────────────────────────────────────────

    /**
     * Join (or rejoin) a room. Rejoining reuses the member row and keeps the
     * role, so reconnects never duplicate people or change roles; the new
     * connection replaces any older one.
     *
     * @return array{member: Member, token: SessionToken, rejoined: bool}
     */
    public function join(ParticipantIdentity $who, Room $room): array
    {
        $this->access->ensureCanJoin($who, $room);
        $rejoined = false;

        $member = $this->locked($room, function (Room $room) use ($who, &$rejoined) {
            // Re-check inside the lock: the room may have ended, or a ban landed, meanwhile.
            $this->access->ensureCanJoin($who, $room);

            $member = $this->access->member($who, $room);
            $rejoined = $member !== null && $member->joined_at !== null;
            $wasActive = $member?->state === MemberState::Joined;

            if (! $member) {
                $member = new Member([
                    'room_id' => $room->id,
                    'participant_key' => $who->key,
                    'role' => RoomRole::Listener,
                ]);
            }

            if (! $wasActive && $member->role === RoomRole::Listener) {
                $listeners = $room->activeCount(RoomRole::Listener);
                if ($listeners >= $room->max_listeners) {
                    throw RoomActionDenied::because('room_full', 409);
                }
            }

            $member->forceFill([
                'display_name' => $who->displayName,
                'avatar_url' => $who->avatarUrl,
                'profile_url' => $who->profileUrl,
                'state' => MemberState::Joined,
                'connection_id' => Str::random(24),
                // Microphones always start off; the participant turns theirs on.
                'self_muted' => true,
                'joined_at' => $member->joined_at ?? now(),
                'last_seen_at' => now(),
                'left_at' => null,
            ])->save();

            $this->touch($room);

            return $member;
        });

        $this->signals->touchPresence($room->id, $who->key, (string) $member->connection_id);
        $this->signals->forget('host-absent:'.$room->id);

        Events\ParticipantJoined::dispatch($room, $who->key, $member->role->value, $rejoined);

        return [
            'member' => $member,
            'token' => SessionToken::issue($room->id, $who->key, (string) $member->connection_id),
            'rejoined' => $rejoined,
        ];
    }

    public function leave(ParticipantIdentity $who, Room $room, string $reason = 'left'): void
    {
        $left = $this->locked($room, function (Room $room) use ($who) {
            $member = $this->access->member($who, $room);
            if (! $member || $member->state !== MemberState::Joined) {
                return false;
            }

            $this->markLeft($member);
            $this->handOverHostIfNeeded($room, $member);
            $this->touch($room);

            return true;
        });

        $this->signals->forgetPresence($room->id, $who->key);

        if ($left) {
            Events\ParticipantLeft::dispatch($room, $who->key, $reason);
        }
    }

    /**
     * Validate a session token against the member's current connection.
     * Older tabs (replaced by a newer join) and expired tokens are rejected.
     */
    /** @return array{0: Member, 1: SessionToken} */
    public function memberForToken(ParticipantIdentity $who, Room $room, ?string $rawToken): array
    {
        $token = SessionToken::decode($rawToken);
        if (! $token || $token->roomId !== $room->id || $token->participantKey !== $who->key) {
            throw RoomActionDenied::because('session_expired', 401);
        }

        $member = $this->access->ensureMember($who, $room);
        if ($member->connection_id !== $token->connectionId) {
            throw RoomActionDenied::because('replaced', 409);
        }

        return [$member, $token];
    }

    /**
     * Heartbeat: keep presence alive and occasionally persist last-seen.
     * $hidden pages (locked phones, background tabs) are kept for longer.
     */
    public function heartbeat(Room $room, Member $member, bool $hidden = false): void
    {
        $this->signals->touchPresence($room->id, $member->participant_key, (string) $member->connection_id, $hidden);

        $this->signals->throttle('seen:'.$member->id, 20, function () use ($member) {
            $member->newQuery()->whereKey($member->id)->update(['last_seen_at' => now()]);
        });
        $this->signals->throttle('reconcile:'.$room->id, 5, fn () => $this->reconcile($room));
    }

    /**
     * Mark members whose browsers stopped checking in as disconnected, hand
     * the host role on if needed, and end rooms left without any host.
     */
    /** Reconcile at most every few seconds per room (cheap enough to call from lists and syncs). */
    public function tidy(Room $room): void
    {
        $this->signals->throttle('reconcile:'.$room->id, 5, fn () => $this->reconcile($room));
    }

    public function reconcile(Room $room): void
    {
        $room->refresh();
        if ($room->isEnded()) {
            return;
        }

        $timeout = (int) config('creators-audio-rooms.lifecycle.presence_timeout_seconds', 25);
        $stale = [];

        $this->locked($room, function (Room $room) use ($timeout, &$stale) {
            $changed = false;
            foreach ($room->activeMembers()->get() as $member) {
                $presence = $this->signals->presence($room->id, $member->participant_key);
                $alive = $presence !== null
                    || ($member->last_seen_at && $member->last_seen_at->gt(now()->subSeconds($timeout)));
                if (! $alive) {
                    $this->markLeft($member);
                    $this->handOverHostIfNeeded($room, $member);
                    $stale[] = $member->participant_key;
                    $changed = true;
                }
            }
            if ($changed) {
                $this->touch($room);
            }

            return $room;
        });

        foreach ($stale as $key) {
            Events\ParticipantLeft::dispatch($room, $key, 'disconnected');
        }

        $this->endIfHostAbsent($room->fresh());
    }

    // ─── Speaking ────────────────────────────────────────────────────────

    public function raiseHand(ParticipantIdentity $who, Room $room, bool $raised): void
    {
        $changed = $this->locked($room, function (Room $room) use ($who, $raised) {
            $member = $this->access->ensureMember($who, $room);
            if ($member->role !== RoomRole::Listener) {
                return false;
            }
            if ($raised && ! $this->access->canRequestToSpeak($who, $room)) {
                throw RoomActionDenied::because('not_allowed');
            }
            if ($raised === ($member->hand_raised_at !== null)) {
                return false;
            }

            $member->forceFill(['hand_raised_at' => $raised ? now() : null])->save();
            $this->touch($room);

            return true;
        });

        if ($changed && $raised) {
            Events\HandRaised::dispatch($room, $who->key);
        }
    }

    /**
     * Turn your own microphone on or off. Turning it on is refused while a
     * moderator has muted you, until they allow it again.
     */
    public function setSelfMuted(ParticipantIdentity $who, Room $room, bool $muted): Member
    {
        return $this->locked($room, function (Room $room) use ($who, $muted) {
            $member = $this->access->ensureMember($who, $room);
            if (! $muted && ! $member->role->canSpeak()) {
                throw RoomActionDenied::because('not_a_speaker', 409);
            }
            if (! $muted && $member->moderator_muted) {
                throw RoomActionDenied::because('muted_by_moderator', 409);
            }
            if ($member->self_muted !== $muted) {
                $member->forceFill(['self_muted' => $muted])->save();
                $this->touch($room);
            }

            return $member;
        });
    }

    // ─── Moderation ──────────────────────────────────────────────────────

    /**
     * approve_speaker promotes a listener who asked to speak (hand raised).
     * invite_speaker asks a listener who did not; they accept or decline.
     */
    public const MODERATION_ACTIONS = [
        'approve_speaker', 'decline_request', 'invite_speaker', 'cancel_invite', 'make_co_host', 'demote',
        'mute', 'allow_unmute', 'remove', 'ban',
    ];

    public function moderate(ParticipantIdentity $who, Room $room, string $action, string $targetKey): Member
    {
        if (! in_array($action, self::MODERATION_ACTIONS, true)) {
            throw RoomActionDenied::because('unknown_action', 422);
        }

        $required = match ($action) {
            'approve_speaker', 'decline_request', 'invite_speaker', 'cancel_invite', 'make_co_host', 'demote' => RoomAction::ManageSpeakers,
            'mute', 'allow_unmute' => RoomAction::Mute,
            'remove' => RoomAction::Remove,
            'ban' => RoomAction::Ban,
        };

        $events = [];
        $target = $this->locked($room, function (Room $room) use ($who, $action, $targetKey, $required, &$events) {
            $actor = $this->access->ensureModerator($who, $room, $required);

            if ($targetKey === $who->key) {
                throw RoomActionDenied::because('not_allowed');
            }

            $target = Member::query()->where('room_id', $room->id)->where('participant_key', $targetKey)->first();
            if (! $target || ($target->state !== MemberState::Joined && ! in_array($action, ['ban', 'remove'], true))) {
                throw RoomActionDenied::because('stale', 409);
            }
            // Co-hosts cannot act on the host.
            if ($target->role === RoomRole::Host && $actor->role !== RoomRole::Host) {
                throw RoomActionDenied::because('not_allowed');
            }

            $from = $target->role;
            match ($action) {
                // Approving someone who never asked would put them on stage without consent: invite instead.
                'approve_speaker' => $target->hand_raised_at === null && $target->role === RoomRole::Listener
                    ? $this->invite($room, $target, $who->key)
                    : $this->promote($room, $target, RoomRole::Speaker),
                'invite_speaker' => $this->invite($room, $target, $who->key),
                'cancel_invite' => $target->forceFill(['invited_to_speak_at' => null, 'invited_by_key' => null])->save(),
                'make_co_host' => $this->makeCoHost($room, $target),
                'demote' => $this->promote($room, $target, RoomRole::Listener),
                'decline_request' => $target->forceFill(['hand_raised_at' => null])->save(),
                'mute' => $target->role->canSpeak()
                    ? $target->forceFill(['moderator_muted' => true, 'self_muted' => true])->save()
                    : throw RoomActionDenied::because('not_a_speaker', 409),
                'allow_unmute' => $target->forceFill(['moderator_muted' => false])->save(),
                'remove', 'ban' => $this->removeMember($room, $target, $who->key, $action === 'ban'),
            };

            if ($from !== $target->role) {
                $events[] = new Events\RoleChanged($room, $target->participant_key, $from->value, $target->role->value, $who->key);
            }
            if ($action === 'mute') {
                $events[] = new Events\ParticipantMuted($room, $target->participant_key, $who->key);
            }
            if ($target->invited_to_speak_at !== null && $target->wasChanged('invited_to_speak_at')) {
                $events[] = new Events\SpeakerInvited($room, $target->participant_key, $who->key);
            }
            if (in_array($action, ['remove', 'ban'], true)) {
                $events[] = new Events\ParticipantRemoved($room, $target->participant_key, $who->key, $action === 'ban');
            }

            $this->moderationLog($room, $who->key, $target->participant_key, $action);
            $this->touch($room);

            return $target;
        });

        if (in_array($action, ['remove', 'ban'], true)) {
            $this->signals->forgetPresence($room->id, $targetKey);
        }
        foreach ($events as $event) {
            event($event);
            if ($event instanceof Events\SpeakerInvited) {
                $this->safely(fn () => $this->notifier->invitedToSpeak($room, $event->participantKey), 'notifier.invitedToSpeak', $room);
            }
        }

        return $target;
    }

    /** A listener answers an invitation to speak. Accepting takes a speaker place if one is free. */
    public function respondToInvitation(ParticipantIdentity $who, Room $room, bool $accept): Member
    {
        $event = null;
        $member = $this->locked($room, function (Room $room) use ($who, $accept, &$event) {
            $member = $this->access->ensureMember($who, $room);
            if ($member->invited_to_speak_at === null || $member->role !== RoomRole::Listener) {
                throw RoomActionDenied::because('no_invitation', 409);
            }

            if ($accept) {
                $this->promote($room, $member, RoomRole::Speaker);
                $event = new Events\RoleChanged($room, $member->participant_key, RoomRole::Listener->value, RoomRole::Speaker->value, $member->invited_by_key);
            }
            $member->forceFill(['invited_to_speak_at' => null, 'invited_by_key' => null])->save();
            $this->moderationLog($room, $who->key, null, $accept ? 'invite_accepted' : 'invite_declined');
            $this->touch($room);

            return $member;
        });

        if ($event) {
            event($event);
        }

        return $member;
    }

    /**
     * Anonymise a participant everywhere (e.g. when the host app deletes or
     * anonymises an account): removes them from live rooms and replaces their
     * stored name, avatar and key.
     */
    public function forgetParticipant(string $participantKey): int
    {
        $anonymousKey = 'anonymised:'.substr(hash('sha256', $participantKey.config('app.key')), 0, 32);
        $connection = DB::connection(Tables::connection());

        return $connection->transaction(function () use ($participantKey, $anonymousKey) {
            $members = Member::query()->where('participant_key', $participantKey)->get();
            foreach ($members as $member) {
                $member->forceFill([
                    'participant_key' => $anonymousKey,
                    'display_name' => __('Former participant'),
                    'avatar_url' => null,
                    'profile_url' => null,
                    'invited_to_speak_at' => null,
                    'state' => $member->state === MemberState::Joined ? MemberState::Left : $member->state,
                    'connection_id' => null,
                    'hand_raised_at' => null,
                ])->save();
                Room::query()->whereKey($member->room_id)->increment('version');
                $this->signals->forgetPresence($member->room_id, $participantKey);
            }

            Room::query()->where('created_by_key', $participantKey)->update(['created_by_key' => $anonymousKey]);
            Ban::query()->where('participant_key', $participantKey)->update(['participant_key' => $anonymousKey]);
            Ban::query()->where('banned_by_key', $participantKey)->update(['banned_by_key' => $anonymousKey]);
            ModerationLogEntry::query()->where('actor_key', $participantKey)->update(['actor_key' => $anonymousKey]);
            ModerationLogEntry::query()->where('target_key', $participantKey)->update(['target_key' => $anonymousKey]);

            return $members->count();
        });
    }

    // ─── Internals ───────────────────────────────────────────────────────

    private function invite(Room $room, Member $target, string $byKey): void
    {
        if ($target->role !== RoomRole::Listener) {
            return;
        }
        if ($room->activeCount(RoomRole::Host, RoomRole::CoHost, RoomRole::Speaker) >= $room->max_speakers) {
            throw RoomActionDenied::because('speakers_full', 409);
        }
        $target->forceFill(['invited_to_speak_at' => now(), 'invited_by_key' => $byKey, 'hand_raised_at' => null])->save();
    }

    private function promote(Room $room, Member $target, RoomRole $to): void
    {
        if ($target->role === $to) {
            return;
        }
        if ($to->canSpeak() && ! $target->role->canSpeak()) {
            if ($room->activeCount(RoomRole::Host, RoomRole::CoHost, RoomRole::Speaker) >= $room->max_speakers) {
                throw RoomActionDenied::because('speakers_full', 409);
            }
        }
        if ($to === RoomRole::Listener && $target->role === RoomRole::Host) {
            throw RoomActionDenied::because('not_allowed');
        }

        $target->forceFill([
            'role' => $to,
            'hand_raised_at' => null,
            'invited_to_speak_at' => null,
            'invited_by_key' => null,
            // New speakers start muted; demoted speakers stop sending.
            'self_muted' => true,
            'moderator_muted' => $to->canSpeak() ? $target->moderator_muted : false,
        ])->save();
    }

    private function makeCoHost(Room $room, Member $target): void
    {
        if ($target->role->isHost()) {
            return;
        }
        $identity = app(ParticipantResolver::class)->find($target->participant_key);
        if (! $identity || ! $this->access->canHost($identity, $room)) {
            throw RoomActionDenied::because('cannot_host', 409);
        }
        if ($room->activeCount(RoomRole::Host, RoomRole::CoHost) >= $room->max_hosts) {
            throw RoomActionDenied::because('hosts_full', 409);
        }
        if (! $target->role->canSpeak()
            && $room->activeCount(RoomRole::Host, RoomRole::CoHost, RoomRole::Speaker) >= $room->max_speakers) {
            throw RoomActionDenied::because('speakers_full', 409);
        }

        $target->forceFill(['role' => RoomRole::CoHost, 'hand_raised_at' => null])->save();
    }

    private function removeMember(Room $room, Member $target, string $byKey, bool $ban): void
    {
        $target->forceFill([
            'state' => MemberState::Removed,
            'connection_id' => null,
            'hand_raised_at' => null,
            'left_at' => now(),
            'role' => $target->role->isHost() ? RoomRole::Listener : $target->role,
        ])->save();

        if ($ban && ! Ban::query()->where('room_id', $room->id)->where('participant_key', $target->participant_key)->exists()) {
            Ban::create([
                'room_id' => $room->id,
                'tenant_key' => $room->tenant_key,
                'participant_key' => $target->participant_key,
                'banned_by_key' => $byKey,
            ]);
        }
    }

    private function markLeft(Member $member): void
    {
        $member->forceFill([
            'state' => MemberState::Left,
            'connection_id' => null,
            'hand_raised_at' => null,
            'invited_to_speak_at' => null,
            'self_muted' => true,
            'left_at' => now(),
        ])->save();
    }

    /** When the host leaves, the longest-present co-host becomes host. */
    private function handOverHostIfNeeded(Room $room, Member $leaving): void
    {
        if ($leaving->role !== RoomRole::Host) {
            return;
        }

        $successor = $room->activeMembers()
            ->where('role', RoomRole::CoHost->value)
            ->orderBy('joined_at')
            ->first();
        if (! $successor) {
            return;
        }

        $successor->forceFill(['role' => RoomRole::Host])->save();
        $leaving->forceFill(['role' => RoomRole::CoHost])->save();
        $this->moderationLog($room, 'system', $successor->participant_key, 'host_handover', ['from' => $leaving->participant_key]);
        Events\RoleChanged::dispatch($room, $successor->participant_key, RoomRole::CoHost->value, RoomRole::Host->value, null);
    }

    private function endIfHostAbsent(Room $room): void
    {
        if (! $room->isLive()) {
            return;
        }
        if ($room->activeCount(RoomRole::Host, RoomRole::CoHost) > 0) {
            $this->signals->forget('host-absent:'.$room->id);

            return;
        }

        $grace = (int) config('creators-audio-rooms.lifecycle.host_absent_grace_seconds', 120);
        $since = $this->signals->remember('host-absent:'.$room->id, $grace * 4, fn () => now()->getTimestamp());
        if (now()->getTimestamp() - (int) $since >= $grace) {
            $this->signals->forget('host-absent:'.$room->id);
            $this->close($room, 'host_absent');
        }
    }

    /**
     * Run $callback with the room row locked and freshly loaded.
     *
     * @template T
     *
     * @param  callable(Room): T  $callback
     * @return T
     */
    private function locked(Room $room, callable $callback): mixed
    {
        return DB::connection(Tables::connection())->transaction(function () use ($room, $callback) {
            $fresh = Room::query()->whereKey($room->id)->lockForUpdate()->first();
            if (! $fresh) {
                throw RoomActionDenied::because('room_not_found', 404);
            }
            $result = $callback($fresh);
            $room->setRawAttributes($fresh->getAttributes(), true);

            return $result;
        }, 3);
    }

    /** Bump the version so every client refreshes its view of the room. */
    private function touch(Room $room): void
    {
        $room->version = (int) $room->version + 1;
        $room->save();
    }

    /** @param  array<string, mixed>  $meta */
    private function moderationLog(Room $room, string $actorKey, ?string $targetKey, string $action, array $meta = []): void
    {
        ModerationLogEntry::create([
            'room_id' => $room->id,
            'actor_key' => $actorKey,
            'target_key' => $targetKey,
            'action' => $action,
            'meta' => $meta ?: null,
        ]);
    }

    /** Optional host integrations must never break a room action. */
    private function safely(callable $callback, string $what, Room $room): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            report($e);
            $this->log('warning', $what.' failed', $room, null, ['error' => $e->getMessage()]);
        }
    }

    /** @param  array<string, mixed>  $context */
    private function log(string $level, string $message, Room $room, ?string $actorKey, array $context = []): void
    {
        Log::{$level}('creators-audio-rooms: '.$message, array_filter([
            'room' => $room->id,
            'tenant' => $room->tenant_key,
            'actor' => $actorKey,
        ]) + $context);
    }
}
