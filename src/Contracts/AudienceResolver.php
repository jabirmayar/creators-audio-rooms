<?php

namespace daacreators\CreatorsAudioRooms\Contracts;

use daacreators\CreatorsAudioRooms\Models\Room;
use daacreators\CreatorsAudioRooms\Support\ParticipantIdentity;

/**
 * Rooms can be limited to an application-defined audience (a team, a
 * community, an event, a paid tier…). The package stores the audience as an
 * opaque type + reference and asks the host whether someone belongs to it.
 */
interface AudienceResolver
{
    /** Is this participant part of the room's audience? */
    public function includes(ParticipantIdentity $participant, Room $room): bool;

    /** Human-readable audience label for the room page (e.g. "Design team"), or null. */
    public function label(Room $room): ?string;
}
