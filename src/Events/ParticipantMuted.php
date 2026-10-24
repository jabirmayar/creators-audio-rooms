<?php

namespace daacreators\CreatorsAudioRooms\Events;

use daacreators\CreatorsAudioRooms\Models\Room;

/** A moderator muted a speaker. Only the speaker can turn their microphone back on. */
class ParticipantMuted extends RoomEvent
{
    public function __construct(Room $room, public readonly string $participantKey, public readonly string $byKey)
    {
        parent::__construct($room);
    }
}
