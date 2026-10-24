<?php

namespace daacreators\CreatorsAudioRooms\Tests\Feature;

use daacreators\CreatorsAudioRooms\Http\Controllers\RoomApiController;

class SignalingTest extends ApiTestCase
{
    public function test_sync_needs_a_session_token(): void
    {
        $host = $this->staff();
        $roomId = $this->createLiveRoom($host);
        unset($this->sessions[$this->keyOf($host)]);

        $this->sync($host, $roomId)->assertStatus(401)->assertJson(['error' => 'session_expired']);
    }

    public function test_tampered_and_expired_tokens_are_rejected(): void
    {
        $host = $this->staff();
        $roomId = $this->createLiveRoom($host);
        $key = $this->keyOf($host);
        $valid = $this->sessions[$key];

        $this->sessions[$key] = $valid.'x';
        $this->sync($host, $roomId)->assertStatus(401);

        $this->sessions[$key] = $valid;
        $this->travel(6)->minutes();
        $this->sync($host, $roomId)->assertStatus(401)->assertJson(['error' => 'session_expired']);
    }

    public function test_a_newer_tab_replaces_the_older_one(): void
    {
        $host = $this->staff();
        $roomId = $this->createLiveRoom($host);
        $oldTab = $this->sessions[$this->keyOf($host)];

        $this->join($host, $roomId)->assertOk();
        $this->sync($host, $roomId)->assertOk();

        $this->as($host);
        $this->json('GET', self::API."/rooms/{$roomId}/sync", [], [RoomApiController::TOKEN_HEADER => $oldTab])
            ->assertStatus(409)->assertJson(['error' => 'replaced']);
    }

    public function test_sync_only_sends_state_when_the_room_changed(): void
    {
        $host = $this->staff();
        $roomId = $this->createLiveRoom($host);

        $first = $this->sync($host, $roomId)->assertOk()->assertJsonStructure(['state']);
        $version = $first->json('version');

        $this->sync($host, $roomId, 0, $version)->assertOk()->assertJsonMissingPath('state');

        $this->join($this->member(), $roomId);
        $this->sync($host, $roomId, 0, $version)->assertOk()->assertJsonStructure(['state']);
    }

    public function test_a_speaker_offer_reaches_the_listener_and_the_answer_comes_back(): void
    {
        $host = $this->staff();
        $listener = $this->member();
        $roomId = $this->createLiveRoom($host);
        $this->join($listener, $roomId);

        $offer = ['type' => 'offer', 'sdp' => "v=0\r\na=sendonly\r\n"];
        $this->api($host, 'POST', "/rooms/{$roomId}/signal", ['to' => $this->keyOf($listener), 'type' => 'offer', 'payload' => json_encode($offer)])->assertOk();

        $received = $this->sync($listener, $roomId)->assertOk();
        $this->assertSame('offer', $received->json('messages.0.type'));
        $this->assertSame($this->keyOf($host), $received->json('messages.0.from'));
        $this->assertSame($offer, json_decode($received->json('messages.0.payload'), true), 'SDP arrives byte for byte, trailing line breaks included');

        $answer = ['type' => 'answer', 'sdp' => "v=0\r\na=recvonly\r\n"];
        $this->api($listener, 'POST', "/rooms/{$roomId}/signal", ['to' => $this->keyOf($host), 'type' => 'answer', 'payload' => json_encode($answer)])->assertOk();
        $this->assertSame('answer', $this->sync($host, $roomId)->json('messages.0.type'));

        // The cursor means nothing is delivered twice.
        $cursor = $received->json('cursor');
        $this->assertSame([], $this->sync($listener, $roomId, $cursor)->json('messages'));
    }

    public function test_listeners_cannot_send_audio_or_start_connections(): void
    {
        $host = $this->staff();
        $listener = $this->member();
        $other = $this->member();
        $roomId = $this->createLiveRoom($host);
        $this->join($listener, $roomId);
        $this->join($other, $roomId);

        // A listener may not offer.
        $this->api($listener, 'POST', "/rooms/{$roomId}/signal", ['to' => $this->keyOf($host), 'type' => 'offer', 'payload' => json_encode(['sdp' => 'a=recvonly'])])
            ->assertStatus(422)->assertJson(['error' => 'invalid_signal']);
        // A listener's answer may not try to send audio.
        $this->api($listener, 'POST', "/rooms/{$roomId}/signal", ['to' => $this->keyOf($host), 'type' => 'answer', 'payload' => json_encode(['type' => 'answer', 'sdp' => "v=0\r\na=sendrecv\r\n"])])
            ->assertStatus(422);
        // Two listeners never connect to each other.
        $this->api($listener, 'POST', "/rooms/{$roomId}/signal", ['to' => $this->keyOf($other), 'type' => 'candidate', 'payload' => json_encode(['candidate' => 'x'])])
            ->assertStatus(422);
    }

    public function test_signals_to_people_who_left_are_dropped_quietly(): void
    {
        $host = $this->staff();
        $listener = $this->member();
        $roomId = $this->createLiveRoom($host);
        $this->join($listener, $roomId);
        $this->api($listener, 'POST', "/rooms/{$roomId}/leave");

        $this->api($host, 'POST', "/rooms/{$roomId}/signal", ['to' => $this->keyOf($listener), 'type' => 'offer', 'payload' => json_encode(['sdp' => 'a=sendonly'])])->assertOk();
    }
}
