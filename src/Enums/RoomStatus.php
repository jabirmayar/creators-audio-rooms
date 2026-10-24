<?php

namespace daacreators\CreatorsAudioRooms\Enums;

enum RoomStatus: string
{
    /** Created, not started yet. Only hosts can enter. */
    case Scheduled = 'scheduled';
    case Live = 'live';
    case Ended = 'ended';

    public function isOpen(): bool
    {
        return $this !== self::Ended;
    }
}
