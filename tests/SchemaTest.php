<?php

namespace daacreators\CreatorsAudioRooms\Tests;

use daacreators\CreatorsAudioRooms\Enums\RoomRole;
use daacreators\CreatorsAudioRooms\Enums\RoomStatus;
use daacreators\CreatorsAudioRooms\Models\Member;
use daacreators\CreatorsAudioRooms\Models\Room;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

class SchemaTest extends TestCase
{
    private function makeRoom(array $attributes = []): Room
    {
        return Room::create(array_merge([
            'title' => 'Weekly Q&A',
            'status' => RoomStatus::Scheduled,
            'created_by_key' => 'staff:1',
            'max_hosts' => 2,
            'max_speakers' => 6,
            'max_listeners' => 30,
        ], $attributes));
    }

    public function test_tables_are_created_with_the_configured_prefix(): void
    {
        foreach (['audio_rooms', 'audio_room_members', 'audio_room_bans', 'audio_room_moderation_log'] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table.' should exist');
        }
    }

    public function test_package_tables_have_no_foreign_keys_to_host_users(): void
    {
        foreach (['audio_rooms', 'audio_room_members', 'audio_room_bans', 'audio_room_moderation_log'] as $table) {
            $targets = collect(Schema::getForeignKeys($table))->pluck('foreign_table')->unique()->values()->all();
            $this->assertNotContains('users', $targets, $table);
            $this->assertNotContains('admins', $targets, $table);
        }
    }

    public function test_rooms_use_ulid_keys_and_cast_status(): void
    {
        $room = $this->makeRoom();

        $this->assertSame(26, strlen($room->id));
        $this->assertSame(RoomStatus::Scheduled, $room->fresh()->status);
    }

    public function test_one_member_row_per_participant_per_room(): void
    {
        $room = $this->makeRoom();
        $room->members()->create(['participant_key' => 'user:1', 'display_name' => 'Sam', 'role' => RoomRole::Listener]);

        $this->expectException(QueryException::class);
        $room->members()->create(['participant_key' => 'user:1', 'display_name' => 'Sam again', 'role' => RoomRole::Listener]);
    }

    public function test_the_same_person_can_be_in_different_rooms(): void
    {
        $this->makeRoom()->members()->create(['participant_key' => 'user:1', 'display_name' => 'Sam', 'role' => RoomRole::Listener]);
        $this->makeRoom()->members()->create(['participant_key' => 'user:1', 'display_name' => 'Sam', 'role' => RoomRole::Listener]);

        $this->assertSame(2, Member::where('participant_key', 'user:1')->count());
    }

    public function test_deleting_a_room_removes_its_members(): void
    {
        $room = $this->makeRoom();
        $room->members()->create(['participant_key' => 'user:1', 'display_name' => 'Sam', 'role' => RoomRole::Listener]);

        $room->delete();

        $this->assertSame(0, Member::count());
    }

    public function test_tenant_scope_separates_rooms(): void
    {
        $this->makeRoom(['tenant_key' => 'org-1']);
        $this->makeRoom(['tenant_key' => 'org-2']);
        $this->makeRoom(['tenant_key' => null]);

        $this->assertSame(1, Room::forTenant('org-1')->count());
        $this->assertSame(1, Room::forTenant('org-2')->count());
        $this->assertSame(1, Room::forTenant(null)->count());
    }
}
