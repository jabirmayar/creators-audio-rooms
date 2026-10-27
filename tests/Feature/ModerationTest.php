<?php

namespace daacreators\CreatorsAudioRooms\Tests\Feature;

use daacreators\CreatorsAudioRooms\Models\Ban;
use daacreators\CreatorsAudioRooms\Models\ModerationLogEntry;

class ModerationTest extends ApiTestCase
{
    public function test_hand_raising_queue_and_approval(): void
    {
        $host = $this->staff();
        $first = $this->member();
        $second = $this->member();
        $roomId = $this->createLiveRoom($host);
        $this->join($first, $roomId);
        $this->join($second, $roomId);

        $this->api($first, 'POST', "/rooms/{$roomId}/hand", ['raised' => true])->assertOk()->assertJsonPath('me.hand_raised', true);
        $this->travel(1)->seconds();
        $this->api($second, 'POST', "/rooms/{$roomId}/hand", ['raised' => true])->assertOk()
            ->assertJsonPath('requests', []); // listeners do not get the moderation queue

        $queue = $this->sync($host, $roomId)->json('state.requests');
        $this->assertSame([$this->keyOf($first), $this->keyOf($second)], array_column($queue, 'key'), 'oldest request first');

        $this->moderate($host, $roomId, 'approve_speaker', $first)->assertOk()->assertJsonPath('counts.requests', 1);
        $this->sync($first, $roomId)->assertJsonPath('state.me.role', 'speaker')->assertJsonPath('state.me.self_muted', true);
    }

    public function test_listeners_cannot_moderate(): void
    {
        $host = $this->staff();
        $a = $this->member();
        $b = $this->member();
        $roomId = $this->createLiveRoom($host);
        $this->join($a, $roomId);
        $this->join($b, $roomId);

        $this->moderate($a, $roomId, 'approve_speaker', $b)->assertStatus(403);
        $this->moderate($a, $roomId, 'remove', $host)->assertStatus(403);
    }

    public function test_the_speaker_cap_includes_hosts_and_cannot_be_exceeded(): void
    {
        config(['creators-audio-rooms.limits.max_speakers' => 3]);
        $host = $this->staff();
        $roomId = $this->createLiveRoom($host);
        $members = [$this->member(), $this->member(), $this->member()];
        foreach ($members as $s) {
            $this->join($s, $roomId);
        }

        $this->makeSpeaker($host, $roomId, $members[0])->assertOk();
        $this->makeSpeaker($host, $roomId, $members[1])->assertOk();
        // host + 2 speakers = 3 voices: full, for approvals and invitations alike.
        $this->makeSpeaker($host, $roomId, $members[2])->assertStatus(409)->assertJson(['error' => 'speakers_full']);
        $this->api($members[2], 'POST', "/rooms/{$roomId}/hand", ['raised' => false])->assertOk();
        $this->moderate($host, $roomId, 'invite_speaker', $members[2])->assertStatus(409)->assertJson(['error' => 'speakers_full']);
        $this->api($members[2], 'POST', "/rooms/{$roomId}/hand", ['raised' => true])->assertOk();

        $this->moderate($host, $roomId, 'demote', $members[0])->assertOk();
        $this->moderate($host, $roomId, 'approve_speaker', $members[2])->assertOk();
    }

    public function test_a_moderator_mute_cannot_be_undone_remotely(): void
    {
        $host = $this->staff();
        $speaker = $this->member();
        $roomId = $this->createLiveRoom($host);
        $this->join($speaker, $roomId);
        $this->makeSpeaker($host, $roomId, $speaker)->assertOk();
        $this->api($speaker, 'POST', "/rooms/{$roomId}/mute", ['muted' => false])->assertOk()->assertJsonPath('me.self_muted', false);

        $this->moderate($host, $roomId, 'mute', $speaker)->assertOk();
        $this->sync($speaker, $roomId)->assertJsonPath('state.me.moderator_muted', true)->assertJsonPath('state.me.self_muted', true);

        // The speaker cannot unmute until allowed...
        $this->api($speaker, 'POST', "/rooms/{$roomId}/mute", ['muted' => false])->assertStatus(409)->assertJson(['error' => 'muted_by_moderator']);

        // ...and allowing it does not turn their microphone on for them.
        $this->moderate($host, $roomId, 'allow_unmute', $speaker)->assertOk();
        $this->sync($speaker, $roomId)->assertJsonPath('state.me.moderator_muted', false)->assertJsonPath('state.me.self_muted', true);

        $this->api($speaker, 'POST', "/rooms/{$roomId}/mute", ['muted' => false])->assertOk()->assertJsonPath('me.self_muted', false);
    }

    public function test_listeners_cannot_turn_on_a_microphone(): void
    {
        $listener = $this->member();
        $roomId = $this->createLiveRoom($this->staff());
        $this->join($listener, $roomId);

        $this->api($listener, 'POST', "/rooms/{$roomId}/mute", ['muted' => false])->assertStatus(409)->assertJson(['error' => 'not_a_speaker']);
    }

    public function test_removed_and_banned_participants_cannot_come_back(): void
    {
        $host = $this->staff();
        $removed = $this->member();
        $banned = $this->member();
        $roomId = $this->createLiveRoom($host);
        $this->join($removed, $roomId);
        $this->join($banned, $roomId);

        $this->moderate($host, $roomId, 'remove', $removed)->assertOk();
        $this->sync($removed, $roomId)->assertStatus(409)->assertJson(['error' => 'removed'])->assertJsonStructure(['state']);
        $this->join($removed, $roomId)->assertStatus(403)->assertJson(['error' => 'removed']);

        $this->moderate($host, $roomId, 'ban', $banned)->assertOk();
        $this->join($banned, $roomId)->assertStatus(403)->assertJson(['error' => 'banned']);
        $this->assertTrue(Ban::where('participant_key', $this->keyOf($banned))->exists());

        $this->assertSame(['remove', 'ban'], ModerationLogEntry::where('room_id', $roomId)->whereIn('action', ['remove', 'ban'])->orderBy('id')->pluck('action')->all());
    }

    public function test_co_hosts_cannot_act_on_the_host(): void
    {
        $host = $this->staff();
        $co = $this->staff('co@org-a.test');
        $roomId = $this->createLiveRoom($host);
        $this->join($co, $roomId);
        $this->makeCoHost($host, $roomId, $co)->assertOk();

        $this->moderate($co, $roomId, 'remove', $host)->assertStatus(403);
        $this->moderate($co, $roomId, 'mute', $host)->assertStatus(403);
    }

    public function test_host_limit_is_enforced(): void
    {
        config(['creators-audio-rooms.limits.max_hosts' => 2]);
        $host = $this->staff();
        $co1 = $this->staff('co1@org-a.test');
        $co2 = $this->staff('co2@org-a.test');
        $roomId = $this->createLiveRoom($host);
        $this->join($co1, $roomId);
        $this->join($co2, $roomId);

        $this->makeCoHost($host, $roomId, $co1)->assertOk();
        $this->moderate($host, $roomId, 'make_co_host', $co2)->assertStatus(409)->assertJson(['error' => 'hosts_full']);
    }

    public function test_acting_on_someone_who_already_left_is_reported_as_stale(): void
    {
        $host = $this->staff();
        $member = $this->member();
        $roomId = $this->createLiveRoom($host);
        $this->join($member, $roomId);
        $this->api($member, 'POST', "/rooms/{$roomId}/leave");

        $this->moderate($host, $roomId, 'approve_speaker', $member)->assertStatus(409)->assertJson(['error' => 'stale']);
    }
}
