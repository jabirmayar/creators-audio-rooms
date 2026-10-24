<?php

namespace daacreators\CreatorsAudioRooms\Enums;

/**
 * Roles inside a room. These are separate from any application role
 * (admin, moderator, member, …); the host app only decides who may take them.
 */
enum RoomRole: string
{
    case Host = 'host';
    case CoHost = 'co_host';
    case Speaker = 'speaker';
    case Listener = 'listener';

    public function canSpeak(): bool
    {
        return $this !== self::Listener;
    }

    public function canModerate(): bool
    {
        return $this === self::Host || $this === self::CoHost;
    }

    public function isHost(): bool
    {
        return $this === self::Host || $this === self::CoHost;
    }
}
