<?php

namespace daacreators\CreatorsAudioRooms\Tests\Fixtures;

use daacreators\CreatorsAudioRooms\Contracts\Notifier;
use daacreators\CreatorsAudioRooms\Models\Room;

class RecordingNotifier implements Notifier
{
    /** @var list<array{string, string, ?string}> */
    public static array $calls = [];

    public function roomStarted(Room $room): void
    {
        self::$calls[] = ['started', $room->id, null];
    }

    public function invitedToSpeak(Room $room, string $participantKey): void
    {
        self::$calls[] = ['invited', $room->id, $participantKey];
    }
}
