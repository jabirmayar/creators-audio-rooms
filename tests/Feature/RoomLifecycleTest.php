<?php

namespace daacreators\CreatorsAudioRooms\Tests\Feature;

use daacreators\CreatorsAudioRooms\Events\RoomEnded;
use daacreators\CreatorsAudioRooms\Models\Member;
use daacreators\CreatorsAudioRooms\Models\Room;
use daacreators\CreatorsAudioRooms\Services\RoomManager;
use daacreators\CreatorsAudioRooms\Signaling\SignalStore;
use daacreators\CreatorsAudioRooms\Tests\Fixtures\EmailDomainTenants;
use Illuminate\Support\Facades\Event;

class RoomLifecycleTest extends ApiTestCase
{
    public function test_signed_out_requests_are_rejected(): void
    {
        $this->postJson(self::API.'/rooms', ['title' => 'x'])->assertStatus(401)->assertJson(['error' => 'not_signed_in']);
    }

    public function test_create_join_start_listen_leave_and_end(): void
    {
        $host = $this->staff();
        $listener = $this->member();

        $roomId = $this->api($host, 'POST', '/rooms', ['title' => 'Weekly Q&A'])->assertCreated()->json('room.id');
        $this->assertSame('scheduled', Room::find($roomId)->status->value);

        // Listeners wait until the room is live; the host can enter early.
        $this->join($listener, $roomId)->assertStatus(409)->assertJson(['error' => 'room_not_live']);
        $this->join($host, $roomId)->assertOk()->assertJsonPath('state.me.role', 'host');
        $this->api($host, 'POST', "/rooms/{$roomId}/start")->assertOk()->assertJsonPath('room.status', 'live');

        $joined = $this->join($listener, $roomId)->assertOk();
        $joined->assertJsonPath('state.me.role', 'listener')->assertJsonStructure(['session', 'ice_servers', 'cursor', 'intervals']);
        $this->assertSame(1, $joined->json('state.counts.listeners'));

        $this->api($listener, 'POST', "/rooms/{$roomId}/leave")->assertOk();
        $this->sync($host, $roomId)->assertOk()->assertJsonPath('state.counts.listeners', 0);

        Event::fake([RoomEnded::class]);
        $this->api($host, 'POST', "/rooms/{$roomId}/end")->assertOk()->assertJsonPath('room.status', 'ended');
        Event::assertDispatched(RoomEnded::class, fn ($e) => $e->reason === 'host');

        $this->join($listener, $roomId)->assertStatus(409)->assertJson(['error' => 'room_ended']);
    }

    public function test_a_host_can_only_have_one_open_room(): void
    {
        $host = $this->staff();
        $this->api($host, 'POST', '/rooms', ['title' => 'One'])->assertCreated();
        $this->api($host, 'POST', '/rooms', ['title' => 'Two'])->assertStatus(409)->assertJson(['error' => 'too_many_rooms']);
    }

    public function test_listeners_cannot_start_or_end_rooms(): void
    {
        $host = $this->staff();
        $listener = $this->member();
        $roomId = $this->createLiveRoom($host);
        $this->join($listener, $roomId);

        $this->api($listener, 'POST', "/rooms/{$roomId}/end")->assertStatus(403);
        $this->assertTrue(Room::find($roomId)->isLive());
    }

    public function test_listener_capacity_is_enforced(): void
    {
        config(['creators-audio-rooms.limits.max_listeners' => 1]);
        $roomId = $this->createLiveRoom($this->staff());

        $this->join($this->member(), $roomId)->assertOk();
        $this->join($this->member(), $roomId)->assertStatus(409)->assertJson(['error' => 'room_full']);
    }

    public function test_the_same_numeric_id_from_two_user_tables_joins_as_two_people(): void
    {
        $host = $this->staff();            // staff:1
        $member = $this->member();         // user:1
        $roomId = $this->createLiveRoom($host);
        $this->join($member, $roomId)->assertOk();

        $this->assertSame(['staff:1', 'user:1'], Member::where('room_id', $roomId)->orderBy('participant_key')->pluck('participant_key')->all());
    }

    public function test_rejoining_reuses_the_member_and_keeps_the_role(): void
    {
        $host = $this->staff();
        $member = $this->member();
        $roomId = $this->createLiveRoom($host);
        $this->join($member, $roomId);
        $this->makeSpeaker($host, $roomId, $member)->assertOk();

        $this->join($member, $roomId)->assertOk()->assertJsonPath('state.me.role', 'speaker');
        $this->assertSame(1, Member::where('room_id', $roomId)->where('participant_key', $this->keyOf($member))->count());
    }

    public function test_tenants_cannot_see_each_others_rooms(): void
    {
        config(['creators-audio-rooms.integration.tenant_resolver' => EmailDomainTenants::class]);
        $hostA = $this->staff('a@org-a.test');
        $memberB = $this->member('b@org-b.test');
        $roomId = $this->createLiveRoom($hostA);

        $this->assertSame('org-a.test', Room::find($roomId)->tenant_key);
        $this->join($memberB, $roomId)->assertStatus(404)->assertJson(['error' => 'room_not_found']);
        $this->api($memberB, 'GET', "/rooms/{$roomId}")->assertStatus(404);
        $this->join($this->member('c@org-a.test'), $roomId)->assertOk();
    }

    public function test_when_the_host_leaves_a_co_host_takes_over(): void
    {
        $host = $this->staff();
        $co = $this->staff('co@org-a.test');
        $roomId = $this->createLiveRoom($host);
        $this->join($co, $roomId);
        $this->moderate($host, $roomId, 'make_co_host', $co)->assertOk();

        $this->api($host, 'POST', "/rooms/{$roomId}/leave")->assertOk();

        $this->sync($co, $roomId)->assertOk()->assertJsonPath('state.me.role', 'host');
        $this->assertTrue(Room::find($roomId)->isLive());
    }

    public function test_stale_members_are_disconnected_and_a_room_without_hosts_ends_after_the_grace_period(): void
    {
        config(['creators-audio-rooms.lifecycle.host_absent_grace_seconds' => 60]);
        $host = $this->staff();
        $member = $this->member();
        $roomId = $this->createLiveRoom($host);
        $this->join($member, $roomId);
        $manager = app(RoomManager::class);
        $signals = app(SignalStore::class);

        // The host's browser stops checking in.
        $signals->forgetPresence($roomId, $this->keyOf($host));
        $this->travel(2)->minutes();
        $signals->touchPresence($roomId, $this->keyOf($member), Member::where('participant_key', $this->keyOf($member))->value('connection_id'));

        $manager->reconcile(Room::find($roomId));
        $this->assertSame('left', Member::where('participant_key', $this->keyOf($host))->first()->state->value);
        $this->assertSame('joined', Member::where('participant_key', $this->keyOf($member))->first()->state->value);
        $this->assertTrue(Room::find($roomId)->isLive(), 'grace period starts');

        $this->travel(61)->seconds();
        $signals->touchPresence($roomId, $this->keyOf($member), Member::where('participant_key', $this->keyOf($member))->value('connection_id'));
        $manager->reconcile(Room::find($roomId));

        $room = Room::find($roomId);
        $this->assertTrue($room->isEnded());
        $this->assertSame('host_absent', $room->end_reason);
    }

    public function test_pages_in_the_background_are_kept_in_the_room_for_longer(): void
    {
        $host = $this->staff();
        $phone = $this->member();
        $roomId = $this->createLiveRoom($host);
        $this->join($phone, $roomId);

        // The phone locks: its last check-in says the page is hidden.
        $this->api($phone, 'GET', "/rooms/{$roomId}/sync?since=0&hidden=1")->assertOk();
        $this->travel(90)->seconds();
        app(SignalStore::class)->touchPresence($roomId, $this->keyOf($host), Member::where('participant_key', $this->keyOf($host))->value('connection_id'));
        app(RoomManager::class)->reconcile(Room::find($roomId));

        $this->assertSame('joined', Member::where('participant_key', $this->keyOf($phone))->first()->state->value, 'still listening after 90s in the background');

        $this->travel(90)->seconds();
        app(SignalStore::class)->touchPresence($roomId, $this->keyOf($host), Member::where('participant_key', $this->keyOf($host))->value('connection_id'));
        app(RoomManager::class)->reconcile(Room::find($roomId));
        $this->assertSame('left', Member::where('participant_key', $this->keyOf($phone))->first()->state->value, 'gone after the hidden timeout');

        // Coming back to the page rejoins with the same role.
        $this->join($phone, $roomId)->assertOk()->assertJsonPath('state.me.role', 'listener');
    }

    public function test_forgetting_a_participant_anonymises_them(): void
    {
        $host = $this->staff();
        $member = $this->member();
        $roomId = $this->createLiveRoom($host);
        $this->join($member, $roomId);

        $this->artisan('creators-audio-rooms:forget', ['participant' => $this->keyOf($member)])->assertSuccessful();

        $this->assertSame(0, Member::where('participant_key', $this->keyOf($member))->count());
        $anonymous = Member::where('room_id', $roomId)->where('participant_key', 'like', 'anonymised:%')->first();
        $this->assertSame('left', $anonymous->state->value);
        $this->assertSame('Former participant', $anonymous->display_name);
    }
}
