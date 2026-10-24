<?php

namespace daacreators\CreatorsAudioRooms\Contracts;

use daacreators\CreatorsAudioRooms\Support\AudienceOption;
use daacreators\CreatorsAudioRooms\Support\ParticipantIdentity;

/**
 * Optional companion to AudienceResolver: lets hosts choose who a room is for
 * when they start it (one or more classes, groups, teams…).
 *
 * When your AudienceResolver also implements this interface, the start-room
 * form shows a "Who can join" picker, the chosen audiences are stored with the
 * room, and only people in at least one of them (plus the room's hosts and
 * monitors) can see or join it.
 */
interface ProvidesAudienceOptions
{
    /**
     * What this host may start a room for.
     *
     * @return list<AudienceOption>
     */
    public function optionsFor(ParticipantIdentity $host): array;

    /** May this host start a room open to everyone (no audience chosen)? */
    public function allowsEveryone(ParticipantIdentity $host): bool;

    /** Does the participant belong to this audience? */
    public function inAudience(ParticipantIdentity $participant, string $type, string $ref): bool;
}
