<?php

namespace daacreators\CreatorsAudioRooms\Models;

use daacreators\CreatorsAudioRooms\Models\Concerns\UsesPackageTable;
use daacreators\CreatorsAudioRooms\Support\Tables;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A ban covers one room, or every room of a tenant when room_id is null.
 *
 * @property int $id
 * @property ?string $room_id
 * @property ?string $tenant_key
 * @property string $participant_key
 * @property string $banned_by_key
 * @property ?string $reason
 * @property ?Carbon $expires_at
 */
class Ban extends Model
{
    use UsesPackageTable;

    protected $guarded = ['id'];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    protected static function packageTable(): string
    {
        return Tables::bans();
    }

    /**
     * @param  Builder<Ban>  $query
     * @return Builder<Ban>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
        });
    }

    /** Is the participant banned from this room (directly or tenant-wide)? */
    public static function blocks(string $participantKey, Room $room): bool
    {
        return static::query()
            ->active()
            ->where('participant_key', $participantKey)
            ->where(function (Builder $q) use ($room) {
                $q->where('room_id', $room->id);
                if ($room->tenant_key !== null) {
                    $q->orWhere(fn (Builder $t) => $t->whereNull('room_id')->where('tenant_key', $room->tenant_key));
                }
            })
            ->exists();
    }
}
