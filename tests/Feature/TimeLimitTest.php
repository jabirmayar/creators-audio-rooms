<?php

namespace daacreators\CreatorsAudioRooms\Tests\Feature;

use daacreators\CreatorsAudioRooms\Events\RoomEnded;
use daacreators\CreatorsAudioRooms\Models\Room;
use daacreators\CreatorsAudioRooms\Services\RoomManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

class TimeLimitTest extends ApiTestCase
{
    public function test_rooms_have_no_deadline_without_a_limit(): void
    {
        config(['creators-audio-rooms.lifecycle.max_room_minutes' => 0]);
        $host = $this->staff();
        $roomId = $this->createLiveRoom($host);

        $room = Room::find($roomId);
        $this->assertNull($room->ends_at);
        $this->assertNull($room->time_limit_minutes);
        $this->assertFalse($this->sync($host, $roomId)->assertOk()->json('state.can.extend'));
    }

    public function test_starting_sets_the_deadline_and_everyone_sees_it(): void
    {
        config(['creators-audio-rooms.lifecycle.max_room_minutes' => 45]);
        Carbon::setTestNow('2026-11-12 10:00:00');

        $host = $this->staff();
        $listener = $this->member();
        $roomId = $this->createLiveRoom($host);

        $room = Room::find($roomId);
        $this->assertSame(45, $room->time_limit_minutes);
        $this->assertSame('2026-11-12 10:45:00', $room->ends_at->format('Y-m-d H:i:s'));

        $joined = $this->join($listener, $roomId)->assertOk();
        $this->assertSame($room->ends_at->toIso8601String(), $joined->json('state.room.ends_at'));
        $this->assertFalse($joined->json('state.can.extend'));
        $this->assertTrue($this->sync($host, $roomId)->json('state.can.extend'));

        Carbon::setTestNow();
    }

    public function test_the_room_ends_on_the_first_check_in_after_the_deadline(): void
    {
        config(['creators-audio-rooms.lifecycle.max_room_minutes' => 30]);
        Carbon::setTestNow('2026-11-12 10:00:00');
        $host = $this->staff();
        $roomId = $this->createLiveRoom($host);

        Carbon::setTestNow('2026-11-12 10:29:00');
        app(RoomManager::class)->reconcile(Room::find($roomId));
        $this->assertSame('live', Room::find($roomId)->status->value);

        Event::fake([RoomEnded::class]);
        Carbon::setTestNow('2026-11-12 10:30:01');
        app(RoomManager::class)->reconcile(Room::find($roomId));

        $room = Room::find($roomId);
        $this->assertSame('ended', $room->status->value);
        $this->assertSame('time_limit', $room->end_reason);
        Event::assertDispatched(RoomEnded::class, fn ($e) => $e->reason === 'time_limit');

        Carbon::setTestNow();
    }

    public function test_a_host_can_extend_once(): void
    {
        config(['creators-audio-rooms.lifecycle.max_room_minutes' => 30]);
        Carbon::setTestNow('2026-11-12 10:00:00');
        $host = $this->staff();
        $listener = $this->member();
        $roomId = $this->createLiveRoom($host);
        $this->join($listener, $roomId);

        $this->api($listener, 'POST', "/rooms/{$roomId}/extend")->assertStatus(403);

        $extended = $this->api($host, 'POST', "/rooms/{$roomId}/extend")->assertOk();
        $this->assertSame('2026-11-12T11:00:00+00:00', $extended->json('room.ends_at'));
        $this->assertFalse($extended->json('can.extend'));

        $this->api($host, 'POST', "/rooms/{$roomId}/extend")->assertStatus(409)->assertJson(['error' => 'cannot_extend']);

        Carbon::setTestNow();
    }

    public function test_extension_can_be_turned_off(): void
    {
        config([
            'creators-audio-rooms.lifecycle.max_room_minutes' => 30,
            'creators-audio-rooms.lifecycle.allow_extension' => false,
        ]);
        $host = $this->staff();
        $roomId = $this->createLiveRoom($host);

        $this->assertFalse($this->sync($host, $roomId)->json('state.can.extend'));
        $this->api($host, 'POST', "/rooms/{$roomId}/extend")->assertStatus(409);
    }
}
