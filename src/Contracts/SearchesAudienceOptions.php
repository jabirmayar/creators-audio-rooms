<?php

namespace daacreators\CreatorsAudioRooms\Contracts;

use daacreators\CreatorsAudioRooms\Support\AudienceOption;
use daacreators\CreatorsAudioRooms\Support\ParticipantIdentity;

/**
 * Optional companion to ProvidesAudienceOptions for audiences too large to list
 * (every student of a school, every customer…). The start-room form shows a search
 * box; matches come from searchOptions() and a chosen key is checked with optionFor()
 * when the room is created, since it need not appear in optionsFor().
 */
interface SearchesAudienceOptions
{
    /**
     * Options matching a free-text query, for this host. Return few (a page), best first.
     *
     * @return list<AudienceOption>
     */
    public function searchOptions(ParticipantIdentity $host, string $query, int $limit = 20): array;

    /** The option behind a "type:ref" key this host may pick, or null when they may not. */
    public function optionFor(ParticipantIdentity $host, string $key): ?AudienceOption;
}
