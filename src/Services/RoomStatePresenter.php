<?php

namespace daacreators\CreatorsAudioRooms\Services;

use daacreators\CreatorsAudioRooms\Contracts\AudienceResolver;
use daacreators\CreatorsAudioRooms\Contracts\Authorizer;
use daacreators\CreatorsAudioRooms\Contracts\ProvidesAudienceOptions;
use daacreators\CreatorsAudioRooms\Enums\MemberState;
use daacreators\CreatorsAudioRooms\Enums\RoomAction;
use daacreators\CreatorsAudioRooms\Enums\RoomRole;
use daacreators\CreatorsAudioRooms\Models\Member;
use daacreators\CreatorsAudioRooms\Models\Room;
use daacreators\CreatorsAudioRooms\Signaling\SignalStore;
use daacreators\CreatorsAudioRooms\Support\ParticipantIdentity;

/**
 * The room as one viewer may see it: display-safe member data (no emails or
 * host IDs beyond the participant key) plus what this viewer is allowed to do.
 */
class RoomStatePresenter
{
    public function __construct(
        private readonly AudienceResolver $audience,
        private readonly RoomAccess $access,
        private readonly SignalStore $signals,
        private readonly Authorizer $authorizer,
    ) {}

    /** @return array<string, mixed> */
    public function summary(Room $room): array
    {
        $audiences = $room->relationLoaded('audiences') ? $room->audiences : $room->audiences()->get();
        $labels = $audiences->map(fn ($a) => $a->label ?: $a->audience_ref)->filter()->values();

        return [
            'id' => $room->id,
            'title' => $room->title,
            'description' => $room->description,
            'status' => $room->status->value,
            // Chosen audiences (teams, groups…) win over the resolver's single label.
            'audience_label' => $labels->isNotEmpty() ? $labels->implode(', ') : $this->audience->label($room),
            'audiences' => $audiences->map(fn ($a) => ['type' => $a->audience_type, 'ref' => $a->audience_ref, 'label' => $a->label])->values()->all(),
            'started_at' => $room->started_at?->toIso8601String(),
            'ended_at' => $room->ended_at?->toIso8601String(),
            'end_reason' => $room->end_reason,
            'limits' => [
                'hosts' => $room->max_hosts,
                'speakers' => $room->max_speakers,
                'listeners' => $room->max_listeners,
            ],
            'version' => $room->version,
        ];
    }

    /**
     * Open rooms this participant may see, live rooms first (used by the room
     * list page and GET /api/rooms). Member counts load in the same query.
     *
     * @return list<array<string, mixed>>
     */
    public function directory(ParticipantIdentity $who, int $limit = 50): array
    {
        $rooms = $this->access->discoverableRooms($who)
            ->with('audiences')
            ->orderByRaw("case when status = 'live' then 0 else 1 end")
            ->latest()
            ->limit($limit)
            ->get()
            ->filter(fn (Room $room) => $this->access->canView($who, $room))
            ->values();

        // People who closed the page without leaving are only counted until they time out.
        $rooms->filter(fn (Room $room) => $room->isLive())->each(fn (Room $room) => $this->manager()->tidy($room));
        $rooms->loadCount([
            'activeMembers as people_count' => fn ($q) => $q->where('role', '!=', RoomRole::Monitor->value),
            'activeMembers as listeners_count' => fn ($q) => $q->where('role', RoomRole::Listener->value),
        ]);
        // The host (present or not) and whoever is speaking right now, for the list cards.
        $voices = [RoomRole::Host->value, RoomRole::CoHost->value, RoomRole::Speaker->value];
        $rooms->load(['members' => fn ($q) => $q->whereIn('role', $voices)
            ->where(fn ($q) => $q->where('state', MemberState::Joined->value)->orWhere('role', RoomRole::Host->value))
            ->orderBy('joined_at')]);

        return $rooms->map(function (Room $room) use ($who) {
            $host = $room->members->firstWhere('role', RoomRole::Host);
            $stage = $room->members->filter(fn (Member $m) => $m->state === MemberState::Joined)->values();

            return $this->summary($room) + [
                'people' => (int) $room->people_count,
                'listeners' => (int) $room->listeners_count,
                'is_mine' => $room->created_by_key === $who->key,
                'host' => $host ? ['name' => $host->display_name, 'avatar_url' => $host->avatar_url, 'key' => $host->participant_key] : null,
                'stage' => $stage->take(5)->map(fn (Member $m) => [
                    'key' => $m->participant_key, 'name' => $m->display_name, 'avatar_url' => $m->avatar_url,
                ])->all(),
                'stage_count' => $stage->count(),
            ];
        })->all();
    }

    /**
     * Picker data for starting a room: whether "Everyone" is allowed and the
     * audiences this host may choose (empty when the app offers none).
     *
     * @return array{enabled: bool, everyone: bool, options: list<array{key: string, type: string, ref: string, label: string, group: ?string}>}
     */
    public function audienceChoices(ParticipantIdentity $host): array
    {
        if (! $this->audience instanceof ProvidesAudienceOptions) {
            return ['enabled' => false, 'everyone' => true, 'options' => []];
        }

        return [
            'enabled' => true,
            'everyone' => $this->audience->allowsEveryone($host),
            'options' => array_map(fn ($o) => $o->toArray(), $this->audience->optionsFor($host)),
        ];
    }

    private function manager(): RoomManager
    {
        return app(RoomManager::class);
    }

    /** @return array<string, mixed> */
    public function forViewer(Room $room, ParticipantIdentity $viewer): array
    {
        $members = $room->members()
            ->where('state', MemberState::Joined->value)
            ->orderBy('joined_at')
            ->get();

        $me = $this->access->member($viewer, $room);
        $isModerator = $me?->state === MemberState::Joined && $me->role->canModerate();

        $public = $members->map(function (Member $member) {
            $data = $member->toPublicArray();
            $data['connected'] = $this->signals->presence($member->room_id, $member->participant_key) !== null;

            return $data;
        });

        $byRole = fn (RoomRole ...$roles) => $public
            ->filter(fn ($m) => in_array($m['role'], array_map(fn ($r) => $r->value, $roles), true))
            ->values()
            ->all();

        $requests = $public
            ->filter(fn ($m) => $m['role'] === RoomRole::Listener->value && $m['hand_raised'])
            ->sortBy('hand_raised_at')
            ->values()
            ->all();

        $host = $room->members()->where('role', RoomRole::Host->value)->first();

        return [
            'room' => $this->summary($room),
            // The room's host even when they are not connected, for "Hosted by …".
            'host' => $host ? ['key' => $host->participant_key, 'name' => $host->display_name, 'avatar_url' => $host->avatar_url] : null,
            'hosts' => $byRole(RoomRole::Host, RoomRole::CoHost),
            'speakers' => $byRole(RoomRole::Speaker),
            'listeners' => $byRole(RoomRole::Listener),
            // Monitors are shown to everyone, so a room always knows it is being monitored.
            'monitors' => $byRole(RoomRole::Monitor),
            // Everyone sees that hands are raised; only moderators get the actionable queue order.
            'requests' => $isModerator ? $requests : [],
            'counts' => [
                'hosts' => count($byRole(RoomRole::Host, RoomRole::CoHost)),
                'voices' => count($byRole(RoomRole::Host, RoomRole::CoHost, RoomRole::Speaker)),
                'listeners' => count($byRole(RoomRole::Listener)),
                'requests' => count($requests),
                'monitors' => count($byRole(RoomRole::Monitor)),
                'people' => $public->filter(fn ($m) => $m['role'] !== RoomRole::Monitor->value)->count(),
            ],
            'me' => $me ? [
                'key' => $me->participant_key,
                'role' => $me->role->value,
                'state' => $me->state->value,
                'hand_raised' => $me->hand_raised_at !== null,
                'invited' => $me->invited_to_speak_at !== null,
                'moderator_muted' => $me->moderator_muted,
                'self_muted' => $me->self_muted,
            ] : null,
            'can' => [
                'moderate' => $isModerator,
                'start' => $me !== null && $me->role->isHost() && ! $room->isLive() && ! $room->isEnded(),
                'end' => $isModerator && ! $room->isEnded()
                    && $this->authorizer->allows($viewer, RoomAction::End, $room),
                'monitor' => ($me === null || ! $me->role->canSpeak()) && $this->access->canMonitor($viewer, $room),
                'request_to_speak' => $me?->state === MemberState::Joined
                    && $me->role === RoomRole::Listener
                    && $this->access->canRequestToSpeak($viewer, $room),
            ],
        ];
    }
}
