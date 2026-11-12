<?php

namespace daacreators\CreatorsAudioRooms\Tests\Fixtures;

use daacreators\CreatorsAudioRooms\Contracts\SearchesAudienceOptions;
use daacreators\CreatorsAudioRooms\Support\AudienceOption;
use daacreators\CreatorsAudioRooms\Support\ParticipantIdentity;

/**
 * Team audiences plus a searchable directory of people ("person:<key>"), too long to list.
 */
class SearchableTeamAudiences extends TeamAudiences implements SearchesAudienceOptions
{
    /** @var array<string, string> participant key => display name */
    public static array $people = [];

    public function searchOptions(ParticipantIdentity $host, string $query, int $limit = 20): array
    {
        $query = strtolower($query);
        $out = [];
        foreach (self::$people as $key => $name) {
            if (str_contains(strtolower($name), $query)) {
                $out[] = AudienceOption::make('person', $key, $name, 'People');
            }
        }

        return array_slice($out, 0, $limit);
    }

    public function optionFor(ParticipantIdentity $host, string $key): ?AudienceOption
    {
        if (! str_starts_with($key, 'person:')) {
            return null;
        }
        $ref = substr($key, strlen('person:'));

        return isset(self::$people[$ref]) ? AudienceOption::make('person', $ref, self::$people[$ref], 'People') : null;
    }

    public function inAudience(ParticipantIdentity $participant, string $type, string $ref): bool
    {
        if ($type === 'person') {
            return $participant->key === $ref;
        }

        return parent::inAudience($participant, $type, $ref);
    }
}
