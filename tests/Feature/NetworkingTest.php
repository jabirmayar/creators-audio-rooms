<?php

namespace daacreators\CreatorsAudioRooms\Tests\Feature;

use daacreators\CreatorsAudioRooms\Tests\Fixtures\EmailDomainTenants;
use Illuminate\Console\Scheduling\Schedule;

class NetworkingTest extends ApiTestCase
{
    public function test_join_returns_short_lived_turn_credentials_without_the_secret(): void
    {
        config([
            'creators-audio-rooms.turn.urls' => ['turn:turn.example.test:3478'],
            'creators-audio-rooms.turn.secret' => 'shared-secret',
            'creators-audio-rooms.turn.ttl_seconds' => 3600,
        ]);
        $host = $this->staff();
        $roomId = $this->createLiveRoom($host);

        $response = $this->join($this->member(), $roomId)->assertOk();
        $turn = collect($response->json('ice_servers'))->firstWhere('urls', ['turn:turn.example.test:3478']);

        $this->assertNotNull($turn);
        [$expires] = explode(':', $turn['username']);
        $this->assertEqualsWithDelta(now()->getTimestamp() + 3600, (int) $expires, 5);
        $this->assertSame(base64_encode(hash_hmac('sha1', $turn['username'], 'shared-secret', true)), $turn['credential']);
        $this->assertStringNotContainsString('shared-secret', $response->getContent());
        $this->assertStringNotContainsString('user:', $turn['username'], 'no participant id is sent to the TURN server');
    }

    public function test_without_a_turn_secret_only_the_configured_servers_are_sent(): void
    {
        config(['creators-audio-rooms.turn.urls' => ['turn:turn.example.test:3478'], 'creators-audio-rooms.turn.secret' => null]);
        $roomId = $this->createLiveRoom($this->staff());

        $servers = $this->join($this->member(), $roomId)->json('ice_servers');

        $this->assertSame(config('creators-audio-rooms.ice_servers'), $servers);
    }

    public function test_join_tells_the_browser_the_audio_bitrate_cap(): void
    {
        config(['creators-audio-rooms.audio.max_bitrate' => 24000]);
        $roomId = $this->createLiveRoom($this->staff());

        $this->join($this->member(), $roomId)->assertJsonPath('audio.max_bitrate', 24000);
    }

    public function test_candidates_can_be_sent_in_one_batch(): void
    {
        $host = $this->staff();
        $listener = $this->member();
        $roomId = $this->createLiveRoom($host);
        $this->join($listener, $roomId);

        $batch = json_encode([['candidate' => 'candidate:1 1 udp 1 10.0.0.1 5000 typ host'], ['candidate' => 'candidate:2 1 udp 1 10.0.0.2 5000 typ host']]);
        $this->api($listener, 'POST', "/rooms/{$roomId}/signal", ['to' => $this->keyOf($host), 'type' => 'candidates', 'payload' => $batch])->assertOk();

        $messages = $this->sync($host, $roomId)->json('messages');
        $this->assertSame('candidates', $messages[0]['type']);
        $this->assertSame($batch, $messages[0]['payload']);
    }

    public function test_a_candidate_batch_must_be_a_short_list(): void
    {
        $host = $this->staff();
        $listener = $this->member();
        $roomId = $this->createLiveRoom($host);
        $this->join($listener, $roomId);
        $send = fn ($payload) => $this->api($listener, 'POST', "/rooms/{$roomId}/signal", ['to' => $this->keyOf($host), 'type' => 'candidates', 'payload' => json_encode($payload)]);

        $send(['candidate' => 'not a list'])->assertStatus(422);
        $send(array_fill(0, 31, ['candidate' => 'x']))->assertStatus(422);
    }

    public function test_room_list_api_returns_visible_rooms_with_counts(): void
    {
        config(['creators-audio-rooms.integration.tenant_resolver' => EmailDomainTenants::class]);
        $host = $this->staff('host@org-a.test');
        $roomId = $this->createLiveRoom($host, ['title' => 'Weekly sync']);
        $this->createLiveRoom($this->staff('other@org-b.test'), ['title' => 'Other org']);
        $this->join($this->member('m@org-a.test'), $roomId);

        $this->as($this->member('viewer@org-a.test'));
        $rooms = $this->getJson('/audio-rooms/api/rooms')->assertOk()->json('rooms');

        $this->assertSame(['Weekly sync'], array_column($rooms, 'title'));
        $this->assertSame(2, $rooms[0]['people']);
        $this->assertSame(1, $rooms[0]['listeners']);
        $this->assertStringEndsWith('/audio-rooms/'.$roomId, $rooms[0]['url']);
    }

    public function test_prune_is_registered_with_the_scheduler_unless_turned_off(): void
    {
        $commands = collect($this->app->make(Schedule::class)->events())->map(fn ($event) => $event->command)->implode(' ');

        $this->assertStringContainsString('creators-audio-rooms:prune', $commands);
    }

    public function test_pages_do_not_use_inline_styles_so_strict_csp_works(): void
    {
        $roomId = $this->createLiveRoom($this->staff());
        $this->as($this->member());

        foreach (['/audio-rooms', '/audio-rooms/'.$roomId] as $page) {
            $html = $this->get($page)->assertOk()->getContent();
            $this->assertStringNotContainsString('style="', $html, $page);
            $this->assertStringNotContainsString('<style', $html, $page);
        }
    }
}
