<?php

namespace daacreators\CreatorsAudioRooms\Events;

use daacreators\CreatorsAudioRooms\Models\Room;

/** A participant left. Reasons: left, disconnected, room_ended. */
class ParticipantLeft extends RoomEvent
{
    public function __construct(Room $room, public readonly string $participantKey, public readonly string $reason)
    {
        parent::__construct($room);
    }
}
