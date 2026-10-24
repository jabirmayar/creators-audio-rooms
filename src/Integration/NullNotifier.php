<?php

namespace daacreators\CreatorsAudioRooms\Integration;

use daacreators\CreatorsAudioRooms\Contracts\Notifier;
use daacreators\CreatorsAudioRooms\Models\Room;

/** Default: no notifications. Listen to the package events or bind your own Notifier. */
class NullNotifier implements Notifier
{
    public function roomStarted(Room $room): void {}

    public function invitedToSpeak(Room $room, string $participantKey): void {}
}
