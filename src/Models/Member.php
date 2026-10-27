<?php

namespace daacreators\CreatorsAudioRooms\Models;

use daacreators\CreatorsAudioRooms\Enums\MemberState;
use daacreators\CreatorsAudioRooms\Enums\RoomRole;
use daacreators\CreatorsAudioRooms\Models\Concerns\UsesPackageTable;
use daacreators\CreatorsAudioRooms\Support\Tables;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $room_id
 * @property string $participant_key
 * @property string $display_name
 * @property ?string $avatar_url
 * @property RoomRole $role
 * @property MemberState $state
 * @property bool $moderator_muted
 * @property bool $self_muted
 * @property ?string $connection_id
 * @property ?string $profile_url
 * @property ?Carbon $hand_raised_at
 * @property ?Carbon $invited_to_speak_at
 * @property ?string $invited_by_key
 * @property ?string $invited_role
 * @property ?Carbon $joined_at
 * @property ?Carbon $last_seen_at
 * @property ?Carbon $left_at
 */
class Member extends Model
{
    use UsesPackageTable;

    protected $guarded = ['id'];

    protected $casts = [
        'role' => RoomRole::class,
        'state' => MemberState::class,
        'hand_raised_at' => 'datetime',
        'invited_to_speak_at' => 'datetime',
        'moderator_muted' => 'boolean',
        'self_muted' => 'boolean',
        'joined_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'left_at' => 'datetime',
    ];

    protected static function packageTable(): string
    {
        return Tables::members();
    }

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'room_id');
    }

    public function isActive(): bool
    {
        return $this->state === MemberState::Joined;
    }

    /**
     * A short, non-secret tag for the current browser connection. Other members
     * use it to notice a reconnect (new tag) and rebuild their audio link.
     */
    public function connectionTag(): ?string
    {
        return $this->connection_id ? substr(hash('sha256', $this->connection_id), 0, 12) : null;
    }

    /** What the open invitation is for ("speaker" or "co_host"), or null without one. */
    public function invitedRole(): ?string
    {
        if ($this->invited_to_speak_at === null) {
            return null;
        }

        return $this->invited_role === RoomRole::CoHost->value ? RoomRole::CoHost->value : RoomRole::Speaker->value;
    }

    /**
     * Display-safe data for other members of the room.
     *
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'key' => $this->participant_key,
            'conn' => $this->connectionTag(),
            'name' => $this->display_name,
            'avatar_url' => $this->avatar_url,
            'profile_url' => $this->profile_url,
            'role' => $this->role->value,
            'hand_raised' => $this->hand_raised_at !== null,
            'hand_raised_at' => $this->hand_raised_at?->toIso8601String(),
            'invited' => $this->invited_to_speak_at !== null,
            'invited_role' => $this->invitedRole(),
            'moderator_muted' => $this->moderator_muted,
            'self_muted' => $this->self_muted,
        ];
    }
}
