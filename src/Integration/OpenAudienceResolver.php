<?php

namespace daacreators\CreatorsAudioRooms\Integration;

use daacreators\CreatorsAudioRooms\Contracts\AudienceResolver;
use daacreators\CreatorsAudioRooms\Models\Room;
use daacreators\CreatorsAudioRooms\Support\ParticipantIdentity;

/** Default: rooms are open to every participant the authorizer allows. */
class OpenAudienceResolver implements AudienceResolver
{
    public function includes(ParticipantIdentity $participant, Room $room): bool
    {
        return true;
    }

    public function label(Room $room): ?string
    {
        return null;
    }
}
