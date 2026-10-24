<?php

namespace daacreators\CreatorsAudioRooms\Console;

use daacreators\CreatorsAudioRooms\Enums\RoomStatus;
use daacreators\CreatorsAudioRooms\Models\Room;
use daacreators\CreatorsAudioRooms\Services\RoomManager;
use Illuminate\Console\Command;

/**
 * Housekeeping, safe to run every few minutes from the scheduler:
 * disconnect stale members, end rooms left without a host, expire rooms
 * that never started and delete ended rooms past the retention period.
 *
 * Live rooms are also reconciled during normal use, so this mainly covers
 * rooms nobody is looking at.
 */
class PruneCommand extends Command
{
    protected $signature = 'creators-audio-rooms:prune';

    protected $description = 'Disconnect stale participants, end abandoned rooms and delete old ones.';

    public function handle(RoomManager $rooms): int
    {
        $lifecycle = (array) config('creators-audio-rooms.lifecycle');

        $reconciled = 0;
        Room::query()->where('status', RoomStatus::Live->value)->each(function (Room $room) use ($rooms, &$reconciled) {
            $rooms->reconcile($room);
            $reconciled++;
        });

        $expired = 0;
        $cutoff = now()->subHours((int) ($lifecycle['unstarted_room_ttl_hours'] ?? 24));
        Room::query()->where('status', RoomStatus::Scheduled->value)->where('created_at', '<', $cutoff)
            ->each(function (Room $room) use ($rooms, &$expired) {
                $rooms->close($room, 'expired');
                $expired++;
            });

        $retentionDays = (int) ($lifecycle['ended_room_retention_days'] ?? 30);
        $deleted = 0;
        if ($retentionDays > 0) {
            $deleted = Room::query()
                ->where('status', RoomStatus::Ended->value)
                ->where('ended_at', '<', now()->subDays($retentionDays))
                ->delete();
        }

        $this->components->info("Checked {$reconciled} live room(s), expired {$expired} unstarted room(s), deleted {$deleted} old room(s).");

        return self::SUCCESS;
    }
}
