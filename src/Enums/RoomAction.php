<?php

namespace daacreators\CreatorsAudioRooms\Enums;

/**
 * Application-level permissions the host authorizer is asked about.
 * Room-level rules (e.g. "only a host may approve a speaker") are applied by
 * the package on top of these.
 */
enum RoomAction: string
{
    case Create = 'create';
    case Discover = 'discover';
    case Join = 'join';
    case Host = 'host';
    case Moderate = 'moderate';
    case RequestToSpeak = 'request_to_speak';
    case ManageSpeakers = 'manage_speakers';
    case Mute = 'mute';
    case Remove = 'remove';
    case Ban = 'ban';
    case End = 'end';
    // Join any room as a monitor (never granted by default).
    case Monitor = 'monitor';
}
