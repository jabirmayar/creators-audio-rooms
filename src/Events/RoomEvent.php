<?php

namespace daacreators\CreatorsAudioRooms\Events;

use daacreators\CreatorsAudioRooms\Models\Room;
use Illuminate\Foundation\Events\Dispatchable;

/** Base for all package events. Listen to these to add notifications, analytics or logging. */
abstract class RoomEvent
{
    use Dispatchable;

    public function __construct(public readonly Room $room) {}
}
