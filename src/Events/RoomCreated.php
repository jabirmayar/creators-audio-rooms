<?php

namespace daacreators\CreatorsAudioRooms\Events;

use daacreators\CreatorsAudioRooms\Models\Room;

/** A room was created (not live yet). */
class RoomCreated extends RoomEvent
{
    public function __construct(Room $room, public readonly string $createdByKey)
    {
        parent::__construct($room);
    }
}
