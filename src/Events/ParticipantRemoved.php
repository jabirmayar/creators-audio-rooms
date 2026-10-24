<?php

namespace daacreators\CreatorsAudioRooms\Events;

use daacreators\CreatorsAudioRooms\Models\Room;

/** A moderator removed a participant (and possibly banned them). */
class ParticipantRemoved extends RoomEvent
{
    public function __construct(Room $room, public readonly string $participantKey, public readonly string $byKey, public readonly bool $banned)
    {
        parent::__construct($room);
    }
}
