<?php

namespace daacreators\CreatorsAudioRooms\Events;

use daacreators\CreatorsAudioRooms\Models\Room;

/** A room ended. Reasons: host, host_absent, expired. */
class RoomEnded extends RoomEvent
{
    public function __construct(Room $room, public readonly string $reason, public readonly ?string $endedByKey = null)
    {
        parent::__construct($room);
    }
}
