<?php

namespace daacreators\CreatorsAudioRooms\Contracts;

use daacreators\CreatorsAudioRooms\Models\Room;

/**
 * Lets the host app tell people about rooms through its own notification
 * system. Calls are wrapped so a failure here never breaks a room action.
 */
interface Notifier
{
    /** A room went live: tell its audience. */
    public function roomStarted(Room $room): void;

    /** A participant was invited to speak. */
    public function invitedToSpeak(Room $room, string $participantKey): void;
}
