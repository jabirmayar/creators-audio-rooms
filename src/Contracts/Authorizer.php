<?php

namespace daacreators\CreatorsAudioRooms\Contracts;

use daacreators\CreatorsAudioRooms\Enums\RoomAction;
use daacreators\CreatorsAudioRooms\Models\Room;
use daacreators\CreatorsAudioRooms\Support\ParticipantIdentity;

/**
 * Application permissions for room actions. The package always applies its
 * own room-level rules as well (a listener can never approve speakers, a
 * banned participant can never join), so this only has to answer
 * "does the application allow this person to do this?".
 *
 * $room is null for actions that are not about one room yet (Create, Discover).
 */
interface Authorizer
{
    public function allows(ParticipantIdentity $participant, RoomAction $action, ?Room $room = null): bool;
}
