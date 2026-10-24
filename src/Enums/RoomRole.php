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
    // Listens without taking a listener place and can moderate everyone; visible to the room.
    case Monitor = 'monitor';

    public function canSpeak(): bool
    {
        return $this === self::Host || $this === self::CoHost || $this === self::Speaker;
    }

    public function canModerate(): bool
    {
        return $this === self::Host || $this === self::CoHost || $this === self::Monitor;
    }

    public function isHost(): bool
    {
        return $this === self::Host || $this === self::CoHost;
    }
}
