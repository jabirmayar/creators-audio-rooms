<?php

namespace daacreators\CreatorsAudioRooms\Events;

use daacreators\CreatorsAudioRooms\Models\Room;

/** A participant's room role changed (e.g. listener to speaker). */
class RoleChanged extends RoomEvent
{
    public function __construct(Room $room, public readonly string $participantKey, public readonly string $from, public readonly string $to, public readonly ?string $byKey)
    {
        parent::__construct($room);
    }
}
