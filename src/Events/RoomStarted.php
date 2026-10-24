<?php

namespace daacreators\CreatorsAudioRooms\Events;

use daacreators\CreatorsAudioRooms\Models\Room;

/** A room went live. */
class RoomStarted extends RoomEvent
{
    public function __construct(Room $room, public readonly string $startedByKey)
    {
        parent::__construct($room);
    }
}
