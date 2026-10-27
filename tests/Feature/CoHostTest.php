<?php

namespace daacreators\CreatorsAudioRooms\Tests\Feature;

use daacreators\CreatorsAudioRooms\Events\CoHostInvited;
use daacreators\CreatorsAudioRooms\Events\RoleChanged;
use daacreators\CreatorsAudioRooms\Events\SpeakerInvited;
use daacreators\CreatorsAudioRooms\Models\Room;
use Illuminate\Support\Facades\Event;

class CoHostTest extends ApiTestCase
{
    public function test_a_co_host_invitation_changes_nothing_until_it_is_accepted(): void
    {
        $host = $this->staff();
        $co = $this->staff('co@org-a.test');
        $roomId = $this->createLiveRoom($host);
        $this->join($co, $roomId);

        Event::fake([CoHostInvited::class, SpeakerInvited::class]);
        $this->moderate($host, $roomId, 'make_co_host', $co)->assertOk();
        Event::assertDispatched(CoHostInvited::class);
        Event::assertNotDispatched(SpeakerInvited::class);

        $this->sync($co, $roomId)
            ->assertJsonPath('state.me.role', 'listener')
            ->assertJsonPath('state.me.invited', true)
            ->assertJsonPath('state.me.invited_role', 'co_host')
            ->assertJsonPath('state.counts.hosts', 1);
        // Not a moderator yet.
        $this->moderate($co, $roomId, 'mute', $host)->assertStatus(403);

        Event::fake([RoleChanged::class]);
        $this->api($co, 'POST', "/rooms/{$roomId}/invitation", ['accept' => true])
            ->assertOk()
            ->assertJsonPath('me.role', 'co_host')
            ->assertJsonPath('me.invited', false)
            ->assertJsonPath('me.self_muted', true);
        Event::assertDispatched(RoleChanged::class, fn (RoleChanged $e) => $e->to === 'co_host' && $e->byKey === $this->keyOf($host));
    }

    public function test_a_declined_or_withdrawn_co_host_invitation_keeps_the_role(): void
    {
        $host = $this->staff();
        $co = $this->staff('co@org-a.test');
        $roomId = $this->createLiveRoom($host);
        $this->join($co, $roomId);
        $this->makeSpeaker($host, $roomId, $co)->assertOk();

        $this->moderate($host, $roomId, 'make_co_host', $co)->assertOk();
        $this->api($co, 'POST', "/rooms/{$roomId}/invitation", ['accept' => false])
            ->assertOk()->assertJsonPath('me.role', 'speaker')->assertJsonPath('me.invited', false);

        $this->moderate($host, $roomId, 'make_co_host', $co)->assertOk();
        $this->moderate($host, $roomId, 'cancel_invite', $co)->assertOk();
        $this->api($co, 'POST', "/rooms/{$roomId}/invitation", ['accept' => true])
            ->assertStatus(409)->assertJson(['error' => 'no_invitation']);
        $this->sync($co, $roomId)->assertJsonPath('state.me.role', 'speaker');
    }

    public function test_accepting_rechecks_the_host_limit(): void
    {
        config(['creators-audio-rooms.limits.max_hosts' => 2]);
        $host = $this->staff();
        $co1 = $this->staff('co1@org-a.test');
        $co2 = $this->staff('co2@org-a.test');
        $roomId = $this->createLiveRoom($host);
        $this->join($co1, $roomId);
        $this->join($co2, $roomId);

        // Both invited while a place is free; only the first to accept gets it.
        $this->moderate($host, $roomId, 'make_co_host', $co1)->assertOk();
        $this->moderate($host, $roomId, 'make_co_host', $co2)->assertOk();
        $this->api($co1, 'POST', "/rooms/{$roomId}/invitation", ['accept' => true])->assertOk()->assertJsonPath('me.role', 'co_host');
        $this->api($co2, 'POST', "/rooms/{$roomId}/invitation", ['accept' => true])->assertStatus(409)->assertJson(['error' => 'hosts_full']);
    }

    public function test_a_co_host_cannot_end_the_room_while_the_host_is_in_it(): void
    {
        $host = $this->staff();
        $co = $this->staff('co@org-a.test');
        $roomId = $this->createLiveRoom($host);
        $this->join($co, $roomId);
        $this->makeCoHost($host, $roomId, $co)->assertOk();

        $this->sync($co, $roomId)->assertJsonPath('state.can.end', false)->assertJsonPath('state.can.moderate', true);
        $this->sync($host, $roomId)->assertJsonPath('state.can.end', true);
        $this->api($co, 'POST', "/rooms/{$roomId}/end")->assertStatus(409)->assertJson(['error' => 'host_present']);
        $this->assertTrue(Room::find($roomId)->isLive());

        // Once the host leaves, the co-host takes over and may end it.
        $this->api($host, 'POST', "/rooms/{$roomId}/leave")->assertOk();
        $this->sync($co, $roomId)->assertJsonPath('state.me.role', 'host')->assertJsonPath('state.can.end', true);
        $this->api($co, 'POST', "/rooms/{$roomId}/end")->assertOk();
        $this->assertFalse(Room::find($roomId)->isLive());
    }
}
