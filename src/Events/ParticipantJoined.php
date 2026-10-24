<?php

namespace daacreators\CreatorsAudioRooms\Events;

use daacreators\CreatorsAudioRooms\Models\Room;

/** A participant joined (or rejoined) a room. */
class ParticipantJoined extends RoomEvent
{
    public function __construct(Room $room, public readonly string $participantKey, public readonly string $role, public readonly bool $rejoined)
    {
        parent::__construct($room);
    }
}
