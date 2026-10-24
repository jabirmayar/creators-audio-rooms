<?php

namespace daacreators\CreatorsAudioRooms\Contracts;

use daacreators\CreatorsAudioRooms\Support\ParticipantIdentity;
use Illuminate\Http\Request;

/**
 * Turns the current request into a participant, or null when nobody is
 * signed in (or the signed-in account may not use audio rooms at all).
 *
 * Apps with several user tables or guards return a different `type` per
 * table so identifiers never collide.
 */
interface ParticipantResolver
{
    public function resolve(Request $request): ?ParticipantIdentity;

    /**
     * Re-resolve a participant from its key (e.g. to refresh a display name),
     * or null if the account no longer exists.
     */
    public function find(string $participantKey): ?ParticipantIdentity;
}
