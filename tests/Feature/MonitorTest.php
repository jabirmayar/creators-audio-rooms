<?php

namespace daacreators\CreatorsAudioRooms\Tests\Feature;

use daacreators\CreatorsAudioRooms\Models\Member;
use daacreators\CreatorsAudioRooms\Models\ModerationLogEntry;
use daacreators\CreatorsAudioRooms\Support\ParticipantIdentity;
use Illuminate\Support\Facades\Gate;

class MonitorTest extends ApiTestCase
{
    private function allowStaffToMonitor(): void
    {
        Gate::define('audio-rooms.monitor', fn ($user, ParticipantIdentity $participant) => $participant->type === 'staff');
    }

    private function joinAsMonitor($who, string $roomId)
    {
        $response = $this->api($who, 'POST', "/rooms/{$roomId}/join", ['as' => 'monitor']);
        if ($response->isOk()) {
            $this->sessions[$this->keyOf($who)] = $response->json('session');
        }

        return $response;
    }

    public function test_monitoring_is_not_allowed_unless_the_app_grants_it(): void
    {
        $roomId = $this->createLiveRoom($this->staff());

        $this->joinAsMonitor($this->staff('admin@org-a.test'), $roomId)->assertStatus(403)->assertJson(['error' => 'not_allowed']);
    }

    public function test_a_monitor_gets_in_when_the_room_is_full_and_is_shown_to_everyone(): void
    {
        $this->allowStaffToMonitor();
        config(['creators-audio-rooms.limits.max_listeners' => 1]);
        $roomId = $this->createLiveRoom($this->staff());
        $listener = $this->member();
        $this->join($listener, $roomId)->assertOk();
        $this->join($this->member(), $roomId)->assertStatus(409)->assertJson(['error' => 'room_full']);

        $admin = $this->staff('admin@org-a.test');
        $this->joinAsMonitor($admin, $roomId)->assertOk()
            ->assertJsonPath('state.me.role', 'monitor')
            ->assertJsonPath('state.can.moderate', true);

        $this->sync($listener, $roomId)
            ->assertJsonPath('state.monitors.0.key', $this->keyOf($admin))
            ->assertJsonPath('state.counts.monitors', 1)
            ->assertJsonPath('state.counts.listeners', 1)
            // "N in the room" counts participants, not monitors.
            ->assertJsonPath('state.counts.people', 2);
    }

    public function test_a_monitor_can_moderate_anyone_including_the_host(): void
    {
        $this->allowStaffToMonitor();
        $host = $this->staff();
        $roomId = $this->createLiveRoom($host);
        $speaker = $this->member();
        $this->join($speaker, $roomId);
        $this->makeSpeaker($host, $roomId, $speaker)->assertOk();
        $admin = $this->staff('admin@org-a.test');
        $this->joinAsMonitor($admin, $roomId)->assertOk();

        $this->moderate($admin, $roomId, 'mute', $host)->assertOk();
        $this->moderate($admin, $roomId, 'remove', $speaker)->assertOk();
        $this->assertSame(['mute', 'remove'], ModerationLogEntry::where('actor_key', $this->keyOf($admin))->orderBy('id')->pluck('action')->all());

        $this->api($admin, 'POST', "/rooms/{$roomId}/end")->assertOk()->assertJsonPath('room.status', 'ended');
    }

    public function test_nobody_can_act_on_a_monitor(): void
    {
        $this->allowStaffToMonitor();
        $host = $this->staff();
        $roomId = $this->createLiveRoom($host);
        $admin = $this->staff('admin@org-a.test');
        $this->joinAsMonitor($admin, $roomId)->assertOk();

        foreach (['remove', 'ban', 'mute', 'make_co_host', 'invite_speaker'] as $action) {
            $this->moderate($host, $roomId, $action, $admin)->assertStatus(403);
        }
    }

    public function test_monitors_cannot_speak_or_raise_a_hand_or_start_a_connection(): void
    {
        $this->allowStaffToMonitor();
        $host = $this->staff();
        $roomId = $this->createLiveRoom($host);
        $admin = $this->staff('admin@org-a.test');
        $this->joinAsMonitor($admin, $roomId)->assertOk();

        $this->api($admin, 'POST', "/rooms/{$roomId}/mute", ['muted' => false])->assertStatus(409)->assertJson(['error' => 'not_a_speaker']);
        // Raising a hand is a no-op for anyone who is not a listener.
        $this->api($admin, 'POST', "/rooms/{$roomId}/hand", ['raised' => true])->assertOk()->assertJsonPath('me.hand_raised', false);
        $this->api($admin, 'POST', "/rooms/{$roomId}/signal", ['to' => $this->keyOf($host), 'type' => 'offer', 'payload' => json_encode(['type' => 'offer', 'sdp' => 'v=0'])])
            ->assertStatus(422);
    }

    public function test_room_level_removal_does_not_stop_monitoring_and_speakers_keep_their_role(): void
    {
        $this->allowStaffToMonitor();
        $host = $this->staff();
        $roomId = $this->createLiveRoom($host);
        $admin = $this->staff('admin@org-a.test');
        $this->join($admin, $roomId);
        $this->moderate($host, $roomId, 'remove', $admin)->assertOk();
        $this->join($admin, $roomId)->assertStatus(403);

        $this->joinAsMonitor($admin, $roomId)->assertOk()->assertJsonPath('state.me.role', 'monitor');

        // A co-host asking to monitor stays a co-host.
        $co = $this->staff('co@org-a.test');
        $this->join($co, $roomId);
        $this->moderate($host, $roomId, 'make_co_host', $co)->assertOk();
        $this->joinAsMonitor($co, $roomId)->assertOk()->assertJsonPath('state.me.role', 'co_host');
        $this->assertSame(1, Member::where('room_id', $roomId)->where('role', 'monitor')->count());
    }
}
