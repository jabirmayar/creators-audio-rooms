<?php

namespace daacreators\CreatorsAudioRooms\Events;

use daacreators\CreatorsAudioRooms\Models\Room;

/** A host invited a listener to speak. The listener still has to accept. */
class SpeakerInvited extends RoomEvent
{
    public function __construct(Room $room, public readonly string $participantKey, public readonly string $byKey)
    {
        parent::__construct($room);
    }
}
