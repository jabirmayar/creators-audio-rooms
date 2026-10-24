<?php

namespace daacreators\CreatorsAudioRooms\Tests;

use daacreators\CreatorsAudioRooms\Enums\RoomStatus;
use daacreators\CreatorsAudioRooms\Models\Ban;
use daacreators\CreatorsAudioRooms\Models\Room;

class BanTest extends TestCase
{
    private function makeRoom(?string $tenant = null): Room
    {
        return Room::create([
            'tenant_key' => $tenant,
            'title' => 'Room',
            'status' => RoomStatus::Live,
            'created_by_key' => 'staff:1',
            'max_hosts' => 2,
            'max_speakers' => 6,
            'max_listeners' => 30,
        ]);
    }

    public function test_a_room_ban_blocks_only_that_room(): void
    {
        $room = $this->makeRoom();
        $other = $this->makeRoom();
        Ban::create(['room_id' => $room->id, 'participant_key' => 'user:5', 'banned_by_key' => 'staff:1']);

        $this->assertTrue(Ban::blocks('user:5', $room));
        $this->assertFalse(Ban::blocks('user:5', $other));
        $this->assertFalse(Ban::blocks('user:6', $room), 'other people are not affected');
    }

    public function test_a_tenant_ban_blocks_every_room_in_that_tenant_only(): void
    {
        $orgOne = $this->makeRoom('org-1');
        $orgOneAgain = $this->makeRoom('org-1');
        $orgTwo = $this->makeRoom('org-2');
        Ban::create(['room_id' => null, 'tenant_key' => 'org-1', 'participant_key' => 'user:5', 'banned_by_key' => 'staff:1']);

        $this->assertTrue(Ban::blocks('user:5', $orgOne));
        $this->assertTrue(Ban::blocks('user:5', $orgOneAgain));
        $this->assertFalse(Ban::blocks('user:5', $orgTwo));
    }

    public function test_expired_bans_no_longer_block(): void
    {
        $room = $this->makeRoom();
        Ban::create(['room_id' => $room->id, 'participant_key' => 'user:5', 'banned_by_key' => 'staff:1', 'expires_at' => now()->subMinute()]);
        Ban::create(['room_id' => $room->id, 'participant_key' => 'user:6', 'banned_by_key' => 'staff:1', 'expires_at' => now()->addHour()]);

        $this->assertFalse(Ban::blocks('user:5', $room));
        $this->assertTrue(Ban::blocks('user:6', $room));
    }

    public function test_a_type_qualified_key_never_matches_another_type(): void
    {
        $room = $this->makeRoom();
        Ban::create(['room_id' => $room->id, 'participant_key' => 'user:1', 'banned_by_key' => 'staff:9']);

        $this->assertFalse(Ban::blocks('staff:1', $room), 'staff:1 is a different person from user:1');
    }
}
