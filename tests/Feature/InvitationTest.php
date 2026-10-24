<?php

namespace daacreators\CreatorsAudioRooms\Tests\Feature;

use daacreators\CreatorsAudioRooms\Events\RoleChanged;
use daacreators\CreatorsAudioRooms\Events\SpeakerInvited;
use daacreators\CreatorsAudioRooms\Tests\Fixtures\RecordingNotifier;
use Illuminate\Support\Facades\Event;

class InvitationTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RecordingNotifier::$calls = [];
        config(['creators-audio-rooms.integration.notifier' => RecordingNotifier::class]);
    }

    public function test_an_invited_listener_stays_a_listener_until_they_accept(): void
    {
        $host = $this->staff();
        $listener = $this->member();
        $roomId = $this->createLiveRoom($host);
        $this->join($listener, $roomId);

        $this->moderate($host, $roomId, 'invite_speaker', $listener)->assertOk();

        $this->sync($listener, $roomId)
            ->assertJsonPath('state.me.role', 'listener')
            ->assertJsonPath('state.me.invited', true)
            ->assertJsonPath('state.listeners.0.invited', true);

        Event::fake([RoleChanged::class]);
        $this->api($listener, 'POST', "/rooms/{$roomId}/invitation", ['accept' => true])
            ->assertOk()
            ->assertJsonPath('me.role', 'speaker')
            ->assertJsonPath('me.invited', false)
            // Accepting never turns the microphone on by itself.
            ->assertJsonPath('me.self_muted', true);
        Event::assertDispatched(RoleChanged::class);
    }

    public function test_approving_someone_without_a_raised_hand_sends_an_invitation(): void
    {
        $host = $this->staff();
        $listener = $this->member();
        $roomId = $this->createLiveRoom($host);
        $this->join($listener, $roomId);

        $this->moderate($host, $roomId, 'approve_speaker', $listener)->assertOk();

        $this->sync($listener, $roomId)->assertJsonPath('state.me.role', 'listener')->assertJsonPath('state.me.invited', true);
    }

    public function test_declining_keeps_the_listener_and_clears_the_invitation(): void
    {
        $host = $this->staff();
        $listener = $this->member();
        $roomId = $this->createLiveRoom($host);
        $this->join($listener, $roomId);
        $this->moderate($host, $roomId, 'invite_speaker', $listener)->assertOk();

        $this->api($listener, 'POST', "/rooms/{$roomId}/invitation", ['accept' => false])
            ->assertOk()
            ->assertJsonPath('me.role', 'listener')
            ->assertJsonPath('me.invited', false);

        // Nothing left to answer.
        $this->api($listener, 'POST', "/rooms/{$roomId}/invitation", ['accept' => true])
            ->assertStatus(409)->assertJson(['error' => 'no_invitation']);
    }

    public function test_a_withdrawn_invitation_cannot_be_accepted(): void
    {
        $host = $this->staff();
        $listener = $this->member();
        $roomId = $this->createLiveRoom($host);
        $this->join($listener, $roomId);
        $this->moderate($host, $roomId, 'invite_speaker', $listener)->assertOk();

        $this->moderate($host, $roomId, 'cancel_invite', $listener)->assertOk();

        $this->api($listener, 'POST', "/rooms/{$roomId}/invitation", ['accept' => true])
            ->assertStatus(409)->assertJson(['error' => 'no_invitation']);
        $this->sync($listener, $roomId)->assertJsonPath('state.me.role', 'listener');
    }

    public function test_leaving_clears_an_open_invitation(): void
    {
        $host = $this->staff();
        $listener = $this->member();
        $roomId = $this->createLiveRoom($host);
        $this->join($listener, $roomId);
        $this->moderate($host, $roomId, 'invite_speaker', $listener)->assertOk();

        $this->api($listener, 'POST', "/rooms/{$roomId}/leave")->assertOk();
        $this->join($listener, $roomId)->assertOk()->assertJsonPath('state.me.invited', false);
    }

    public function test_only_moderators_can_invite(): void
    {
        $host = $this->staff();
        $a = $this->member();
        $b = $this->member();
        $roomId = $this->createLiveRoom($host);
        $this->join($a, $roomId);
        $this->join($b, $roomId);

        $this->moderate($a, $roomId, 'invite_speaker', $b)->assertStatus(403);
    }

    public function test_invitations_reach_the_notifier_and_fire_an_event(): void
    {
        $host = $this->staff();
        $listener = $this->member();
        $roomId = $this->createLiveRoom($host);
        $this->join($listener, $roomId);

        Event::fake([SpeakerInvited::class]);
        $this->moderate($host, $roomId, 'invite_speaker', $listener)->assertOk();

        Event::assertDispatched(SpeakerInvited::class, fn (SpeakerInvited $e) => $e->participantKey === $this->keyOf($listener));
        $this->assertContains(['invited', $roomId, $this->keyOf($listener)], RecordingNotifier::$calls);
    }
}
