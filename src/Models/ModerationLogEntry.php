<?php

namespace daacreators\CreatorsAudioRooms\Models;

use daacreators\CreatorsAudioRooms\Models\Concerns\UsesPackageTable;
use daacreators\CreatorsAudioRooms\Support\Tables;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One moderation decision (approve, demote, mute, remove, ban, end).
 *
 * @property int $id
 * @property string $room_id
 * @property string $action
 * @property string $actor_key
 * @property ?string $target_key
 * @property ?array<string, mixed> $meta
 * @property ?Carbon $created_at
 */
class ModerationLogEntry extends Model
{
    use UsesPackageTable;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'meta' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function packageTable(): string
    {
        return Tables::moderationLog();
    }
}
