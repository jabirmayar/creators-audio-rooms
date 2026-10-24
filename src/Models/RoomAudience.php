<?php

namespace daacreators\CreatorsAudioRooms\Models;

use daacreators\CreatorsAudioRooms\Models\Concerns\UsesPackageTable;
use daacreators\CreatorsAudioRooms\Support\Tables;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One audience a room is for (a class, a group, a team…).
 *
 * @property int $id
 * @property string $room_id
 * @property string $audience_type
 * @property string $audience_ref
 * @property ?string $label
 */
class RoomAudience extends Model
{
    use UsesPackageTable;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected static function packageTable(): string
    {
        return Tables::audiences();
    }

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'room_id');
    }

    public function key(): string
    {
        return $this->audience_type.':'.$this->audience_ref;
    }
}
