<?php

namespace daacreators\CreatorsAudioRooms\Enums;

/**
 * A member row is kept for the life of the room and reused on rejoin, so a
 * reconnect never creates a duplicate participant or loses their role.
 */
enum MemberState: string
{
    case Joined = 'joined';
    case Left = 'left';
    case Removed = 'removed';
}
