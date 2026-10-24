<?php

namespace daacreators\CreatorsAudioRooms\Services;

use daacreators\CreatorsAudioRooms\Contracts\AudienceResolver;
use daacreators\CreatorsAudioRooms\Contracts\Authorizer;
use daacreators\CreatorsAudioRooms\Contracts\ProvidesAudienceOptions;
use daacreators\CreatorsAudioRooms\Contracts\TenantResolver;
use daacreators\CreatorsAudioRooms\Enums\MemberState;
use daacreators\CreatorsAudioRooms\Enums\RoomAction;
use daacreators\CreatorsAudioRooms\Exceptions\RoomActionDenied;
use daacreators\CreatorsAudioRooms\Models\Ban;
use daacreators\CreatorsAudioRooms\Models\Member;
use daacreators\CreatorsAudioRooms\Models\Room;
use daacreators\CreatorsAudioRooms\Support\ParticipantIdentity;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every access decision in one place. Application permissions come from the
 * host (Authorizer, AudienceResolver, TenantResolver); room rules (roles,
 * bans, room state) are enforced here on top of them.
 */
class RoomAccess
{
    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly AudienceResolver $audience,
        private readonly TenantResolver $tenants,
    ) {}

    /** Rooms this participant may see listed (tenant + discover permission). */
    /** @return Builder<Room> */
    public function discoverableRooms(ParticipantIdentity $who): Builder
    {
        if (! $this->authorizer->allows($who, RoomAction::Discover)) {
            return Room::query()->whereRaw('1 = 0');
        }

        $tenant = $this->tenants->tenantFor($who);

        return Room::query()
            ->open()
            ->where(function (Builder $q) use ($tenant) {
                $q->whereNull('tenant_key');
                if ($tenant !== null) {
                    $q->orWhere('tenant_key', $tenant);
                }
            });
    }

    /** May this participant see this room at all? Throws "room_not_found" otherwise (no existence leak). */
    public function ensureCanView(ParticipantIdentity $who, Room $room): void
    {
        if (! $this->canView($who, $room)) {
            throw RoomActionDenied::because('room_not_found', 404);
        }
    }

    public function canView(ParticipantIdentity $who, Room $room): bool
    {
        if ($room->tenant_key !== null && ! $this->tenants->canAccessTenant($who, $room->tenant_key)) {
            return false;
        }
        if ($this->isRoomHost($who, $room)) {
            return true;
        }

        return $this->authorizer->allows($who, RoomAction::Discover, $room)
            && $this->audience->includes($who, $room)
            && $this->inChosenAudience($who, $room);
    }

    /**
     * Rooms started for specific audiences (classes, groups…) are only for
     * people in at least one of them. Monitors oversee every room.
     */
    public function inChosenAudience(ParticipantIdentity $who, Room $room): bool
    {
        $audiences = $room->relationLoaded('audiences') ? $room->audiences : $room->audiences()->get();
        if ($audiences->isEmpty()) {
            return true;
        }
        if ($this->authorizer->allows($who, RoomAction::Monitor, $room)) {
            return true;
        }
        if (! $this->audience instanceof ProvidesAudienceOptions) {
            return false;
        }

        foreach ($audiences as $audience) {
            if ($this->audience->inAudience($who, $audience->audience_type, $audience->audience_ref)) {
                return true;
            }
        }

        return false;
    }

    /**
     * $draft is the unsaved room (title, audience, tenant) so the host can
     * decide per audience, e.g. "may this person open a room for this team?".
     */
    public function ensureCanCreate(ParticipantIdentity $who, ?Room $draft = null): void
    {
        if (! $this->authorizer->allows($who, RoomAction::Create, $draft)
            || ! $this->authorizer->allows($who, RoomAction::Host, $draft)) {
            throw RoomActionDenied::because('not_allowed');
        }
        if ($draft?->audience_type !== null && ! $this->audience->includes($who, $draft)) {
            throw RoomActionDenied::because('not_allowed');
        }
    }

    /** May this participant monitor the room (oversight: no listener place, can moderate everyone)? */
    public function canMonitor(ParticipantIdentity $who, Room $room): bool
    {
        return ! $room->isEnded()
            && $this->canView($who, $room)
            && $this->authorizer->allows($who, RoomAction::Monitor, $room);
    }

    public function ensureCanMonitor(ParticipantIdentity $who, Room $room): void
    {
        $this->ensureCanView($who, $room);
        if ($room->isEnded()) {
            throw RoomActionDenied::because('room_ended', 409);
        }
        if (! $this->authorizer->allows($who, RoomAction::Monitor, $room)) {
            throw RoomActionDenied::because('not_allowed');
        }
    }

    public function ensureCanJoin(ParticipantIdentity $who, Room $room): void
    {
        $this->ensureCanView($who, $room);

        if ($room->isEnded()) {
            throw RoomActionDenied::because('room_ended', 409);
        }
        if (Ban::blocks($who->key, $room)) {
            throw RoomActionDenied::because('banned');
        }

        $member = $this->member($who, $room);
        if ($member?->state === MemberState::Removed) {
            throw RoomActionDenied::because('removed');
        }

        // Hosts may enter before the room starts; everyone else waits for it to go live.
        if (! $room->isLive() && ! $member?->role?->isHost()) {
            throw RoomActionDenied::because('room_not_live', 409);
        }
        if (! $member?->role?->isHost() && ! $this->authorizer->allows($who, RoomAction::Join, $room)) {
            throw RoomActionDenied::because('not_allowed');
        }
    }

    /** The caller must be an active member of the room. */
    public function ensureMember(ParticipantIdentity $who, Room $room): Member
    {
        $member = $this->member($who, $room);
        if (! $member || $member->state !== MemberState::Joined) {
            throw RoomActionDenied::because($member?->state === MemberState::Removed ? 'removed' : 'not_a_member', 409);
        }
        if ($room->isEnded()) {
            throw RoomActionDenied::because('room_ended', 409);
        }

        return $member;
    }

    /**
     * The caller must hold a moderating room role AND the application must
     * allow the specific moderation action.
     */
    public function ensureModerator(ParticipantIdentity $who, Room $room, RoomAction $action): Member
    {
        $member = $this->ensureMember($who, $room);
        if (! $member->role->canModerate()) {
            throw RoomActionDenied::because('not_allowed');
        }
        if (! $this->authorizer->allows($who, RoomAction::Moderate, $room)
            || ($action !== RoomAction::Moderate && ! $this->authorizer->allows($who, $action, $room))) {
            throw RoomActionDenied::because('not_allowed');
        }

        return $member;
    }

    public function canRequestToSpeak(ParticipantIdentity $who, Room $room): bool
    {
        return $this->authorizer->allows($who, RoomAction::RequestToSpeak, $room);
    }

    /** May this person hold a host role (host or co-host) in this room? */
    public function canHost(ParticipantIdentity $who, Room $room): bool
    {
        return $this->authorizer->allows($who, RoomAction::Host, $room);
    }

    public function member(ParticipantIdentity $who, Room $room): ?Member
    {
        return Member::query()
            ->where('room_id', $room->id)
            ->where('participant_key', $who->key)
            ->first();
    }

    private function isRoomHost(ParticipantIdentity $who, Room $room): bool
    {
        if ($room->created_by_key === $who->key) {
            return true;
        }

        return (bool) $this->member($who, $room)?->role?->isHost();
    }
}
