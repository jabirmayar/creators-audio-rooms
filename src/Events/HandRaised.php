<?php

namespace daacreators\CreatorsAudioRooms\Events;

use daacreators\CreatorsAudioRooms\Models\Room;

/** A listener asked to speak. */
class HandRaised extends RoomEvent
{
    public function __construct(Room $room, public readonly string $participantKey)
    {
        parent::__construct($room);
    }
}
