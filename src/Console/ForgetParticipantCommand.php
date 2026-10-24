<?php

namespace daacreators\CreatorsAudioRooms\Console;

use daacreators\CreatorsAudioRooms\Services\RoomManager;
use Illuminate\Console\Command;

/**
 * Anonymise a participant across all rooms, e.g. after the account was
 * deleted in the host app. In code, call RoomManager::forgetParticipant().
 */
class ForgetParticipantCommand extends Command
{
    protected $signature = 'creators-audio-rooms:forget {participant : Participant key, e.g. user:42}';

    protected $description = 'Remove a participant from live rooms and anonymise their stored name and key.';

    public function handle(RoomManager $rooms): int
    {
        $key = (string) $this->argument('participant');
        $count = $rooms->forgetParticipant($key);

        $this->components->info("Anonymised {$count} room membership(s) for {$key}.");

        return self::SUCCESS;
    }
}
