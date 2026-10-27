<?php

namespace daacreators\CreatorsAudioRooms\Events;

use daacreators\CreatorsAudioRooms\Models\Room;

/** A host invited someone to be a co-host. They become co-host only when they accept. */
class CoHostInvited extends RoomEvent
{
    public function __construct(Room $room, public readonly string $participantKey, public readonly string $byKey)
    {
        parent::__construct($room);
    }
}
