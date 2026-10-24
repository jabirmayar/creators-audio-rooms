<?php

namespace daacreators\CreatorsAudioRooms\Models;

use daacreators\CreatorsAudioRooms\Enums\MemberState;
use daacreators\CreatorsAudioRooms\Enums\RoomRole;
use daacreators\CreatorsAudioRooms\Enums\RoomStatus;
use daacreators\CreatorsAudioRooms\Models\Concerns\UsesPackageTable;
use daacreators\CreatorsAudioRooms\Support\Tables;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property ?string $tenant_key
 * @property ?string $audience_type
 * @property ?string $audience_ref
 * @property string $title
 * @property ?string $description
 * @property RoomStatus $status
 * @property string $created_by_key
 * @property int $max_hosts
 * @property int $max_speakers
 * @property int $max_listeners
 * @property ?Carbon $started_at
 * @property ?Carbon $ended_at
 * @property ?string $end_reason
 * @property int $version
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property-read int $active_members_count
 */
class Room extends Model
{
    use HasUlids;
    use UsesPackageTable;

    protected $guarded = ['id'];

    protected $casts = [
        'status' => RoomStatus::class,
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'max_hosts' => 'integer',
        'max_speakers' => 'integer',
        'max_listeners' => 'integer',
        'version' => 'integer',
    ];

    protected static function packageTable(): string
    {
        return Tables::rooms();
    }

    /** @return HasMany<Member, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(Member::class, 'room_id');
    }

    /** @return HasMany<Member, $this> */
    public function activeMembers(): HasMany
    {
        return $this->members()->where('state', MemberState::Joined->value);
    }

    /** @return HasMany<Ban, $this> */
    public function bans(): HasMany
    {
        return $this->hasMany(Ban::class, 'room_id');
    }

    /** @return HasMany<ModerationLogEntry, $this> */
    public function moderationLog(): HasMany
    {
        return $this->hasMany(ModerationLogEntry::class, 'room_id');
    }

    /**
     * Restrict to rooms a tenant may see (null tenant = rooms without a tenant).
     *
     * @param  Builder<Room>  $query
     * @return Builder<Room>
     */
    public function scopeForTenant(Builder $query, ?string $tenantKey): Builder
    {
        return $tenantKey === null
            ? $query->whereNull('tenant_key')
            : $query->where('tenant_key', $tenantKey);
    }

    /**
     * @param  Builder<Room>  $query
     * @return Builder<Room>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', '!=', RoomStatus::Ended->value);
    }

    public function isLive(): bool
    {
        return $this->status === RoomStatus::Live;
    }

    public function isEnded(): bool
    {
        return $this->status === RoomStatus::Ended;
    }

    public function activeCount(RoomRole ...$roles): int
    {
        $query = $this->activeMembers();
        if ($roles !== []) {
            $query->whereIn('role', array_map(fn (RoomRole $r) => $r->value, $roles));
        }

        return $query->count();
    }
}
