<?php

namespace daacreators\CreatorsAudioRooms\Integration;

use daacreators\CreatorsAudioRooms\Contracts\Authorizer;
use daacreators\CreatorsAudioRooms\Enums\RoomAction;
use daacreators\CreatorsAudioRooms\Models\Room;
use daacreators\CreatorsAudioRooms\Support\ParticipantIdentity;
use Illuminate\Support\Facades\Gate;

/**
 * Default authorizer for simple apps:
 *
 * - If a Gate ability named "audio-rooms.{action}" is defined, it decides.
 *   It runs for the signed-in user: fn ($user, ParticipantIdentity $participant, ?Room $room).
 * - Otherwise any signed-in participant may create, discover, join and ask
 *   to speak; moderation actions are left to the package's room rules
 *   (only the room's hosts can moderate their own room). Monitoring is never
 *   allowed unless an "audio-rooms.monitor" gate grants it.
 *
 * Replace it in config to map your own permissions.
 */
class DefaultAuthorizer implements Authorizer
{
    public function allows(ParticipantIdentity $participant, RoomAction $action, ?Room $room = null): bool
    {
        $ability = 'audio-rooms.'.$action->value;

        if (Gate::has($ability)) {
            return Gate::allows($ability, [$participant, $room]);
        }

        // Monitoring bypasses room limits and lets someone moderate any room: only when the app grants it.
        return $action !== RoomAction::Monitor;
    }
}
