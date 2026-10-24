<?php

namespace daacreators\CreatorsAudioRooms\Tests\Fixtures;

use daacreators\CreatorsAudioRooms\Contracts\AudienceResolver;
use daacreators\CreatorsAudioRooms\Contracts\ProvidesAudienceOptions;
use daacreators\CreatorsAudioRooms\Models\Room;
use daacreators\CreatorsAudioRooms\Support\AudienceOption;
use daacreators\CreatorsAudioRooms\Support\ParticipantIdentity;

/**
 * Test audiences: teams and clubs with static memberships. Only participants
 * listed in $everyone may start rooms open to everyone.
 */
class TeamAudiences implements AudienceResolver, ProvidesAudienceOptions
{
    /** @var array<string, list<string>> participant key => list of "type:ref" */
    public static array $memberships = [];

    /** @var list<string> participant keys allowed to start rooms for everyone */
    public static array $everyone = [];

    public function includes(ParticipantIdentity $participant, Room $room): bool
    {
        return true;
    }

    public function label(Room $room): ?string
    {
        return null;
    }

    public function optionsFor(ParticipantIdentity $host): array
    {
        return [
            AudienceOption::make('team', 'red', 'Red team', 'Teams'),
            AudienceOption::make('team', 'blue', 'Blue team', 'Teams'),
            AudienceOption::make('club', 'chess', 'Chess club', 'Clubs'),
        ];
    }

    public function allowsEveryone(ParticipantIdentity $host): bool
    {
        return in_array($host->key, self::$everyone, true);
    }

    public function inAudience(ParticipantIdentity $participant, string $type, string $ref): bool
    {
        return in_array($type.':'.$ref, self::$memberships[$participant->key] ?? [], true);
    }
}
