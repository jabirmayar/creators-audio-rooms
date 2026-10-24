<?php

namespace daacreators\CreatorsAudioRooms\Tests;

use daacreators\CreatorsAudioRooms\Enums\RoomStatus;
use daacreators\CreatorsAudioRooms\Models\Room;
use Illuminate\Support\Facades\Schema;

class CustomPrefixTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('creators-audio-rooms.table_prefix', 'lms_voice_');
    }

    public function test_a_custom_prefix_is_used_for_tables_and_models(): void
    {
        $this->assertTrue(Schema::hasTable('lms_voice_rooms'));
        $this->assertTrue(Schema::hasTable('lms_voice_room_members'));
        $this->assertFalse(Schema::hasTable('audio_rooms'));

        $room = Room::create([
            'title' => 'Prefixed',
            'status' => RoomStatus::Scheduled,
            'created_by_key' => 'user:1',
            'max_hosts' => 1,
            'max_speakers' => 4,
            'max_listeners' => 10,
        ]);

        $this->assertSame('lms_voice_rooms', $room->getTable());
        $this->assertSame(1, Room::count());
    }
}
