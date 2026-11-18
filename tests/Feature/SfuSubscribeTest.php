<?php

namespace daacreators\CreatorsAudioRooms\Tests\Feature;

use daacreators\CreatorsAudioRooms\Models\Member;
use daacreators\CreatorsAudioRooms\Models\Room;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;

class SfuSubscribeTest extends ApiTestCase
{
    private const OFFER = ['type' => 'offer', 'sdp' => "v=0\r\n"];

    private const MISSING = ['errorCode' => 'not_found_track_error', 'errorDescription' => 'Track not found on remote peer.'];

    private const EMPTY = ['errorCode' => 'empty_track_error', 'errorDescription' => 'No track data from remote peer.'];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'creators-audio-rooms.transport_mode' => 'sfu',
            'creators-audio-rooms.sfu.cloudflare.app_id' => 'app',
            'creators-audio-rooms.sfu.cloudflare.app_secret' => 'secret',
        ]);
    }

    public function test_a_listener_subscribes_to_every_ready_speaker(): void
    {
        [$roomId, , $listener] = $this->roomWithSpeakers(1);
        $this->fakeCloudflare([['tracks' => [['mid' => '0']], 'sessionDescription' => self::OFFER]]);

        $this->subscribe($listener, $roomId)
            ->assertOk()
            ->assertJsonPath('session_id', 'recv-1')
            ->assertJsonPath('subscriptions.0.mid', '0')
            ->assertJsonPath('unavailable', []);
        $this->assertSame('recv-1', $this->memberOf($listener)->sfu_receiver_session_id);
    }

    public function test_speakers_cloudflare_cannot_hand_over_are_listed_for_a_retry(): void
    {
        [$roomId, $speakers, $listener] = $this->roomWithSpeakers(2);
        $this->fakeCloudflare([['tracks' => [['mid' => '0'], self::EMPTY], 'sessionDescription' => self::OFFER]]);

        $this->subscribe($listener, $roomId)
            ->assertOk()
            ->assertJsonCount(1, 'subscriptions')
            ->assertJsonPath('unavailable', [$this->keyOf($speakers[1])]);
    }

    public function test_reaching_no_speaker_is_not_an_error_and_the_empty_session_is_reused(): void
    {
        [$roomId, , $listener] = $this->roomWithSpeakers(1);
        $this->fakeCloudflare([
            ['tracks' => [self::EMPTY]],
            ['tracks' => [['mid' => '0']], 'sessionDescription' => self::OFFER],
        ]);

        $this->subscribe($listener, $roomId)
            ->assertOk()
            ->assertJsonPath('session_id', 'recv-1')
            ->assertJsonPath('subscriptions', [])
            ->assertJsonPath('session_description', null);

        $this->subscribe($listener, $roomId, ['session_id' => 'recv-1', 'spare' => true])
            ->assertOk()
            ->assertJsonPath('session_id', 'recv-1')
            ->assertJsonCount(1, 'subscriptions');
        $this->assertSame(1, $this->sentTo('/sessions/new'));
    }

    public function test_an_expired_spare_session_is_replaced(): void
    {
        [$roomId, , $listener] = $this->roomWithSpeakers(1);
        $this->fakeCloudflare([
            ['tracks' => [self::EMPTY]],
            Http::response(['errorCode' => 'session_error', 'errorDescription' => 'Session not found'], 404),
            ['tracks' => [['mid' => '0']], 'sessionDescription' => self::OFFER],
        ]);
        $this->subscribe($listener, $roomId)->assertOk()->assertJsonPath('session_id', 'recv-1');

        $this->subscribe($listener, $roomId, ['session_id' => 'recv-1', 'spare' => true])
            ->assertOk()
            ->assertJsonPath('session_id', 'recv-2')
            ->assertJsonCount(1, 'subscriptions');
        $this->assertSame('recv-2', $this->memberOf($listener)->sfu_receiver_session_id);
    }

    public function test_a_spare_from_before_a_rejoin_starts_fresh_but_a_live_session_must_match(): void
    {
        [$roomId, , $listener] = $this->roomWithSpeakers(1);
        $this->fakeCloudflare([['tracks' => [['mid' => '0']], 'sessionDescription' => self::OFFER]]);

        $this->subscribe($listener, $roomId, ['session_id' => 'someone-else'])->assertStatus(403);
        $this->subscribe($listener, $roomId, ['session_id' => 'old-spare', 'spare' => true])
            ->assertOk()
            ->assertJsonPath('session_id', 'recv-1');
    }

    public function test_a_speaker_cloudflare_reports_missing_twice_is_no_longer_advertised(): void
    {
        [$roomId, $speakers, $listener] = $this->roomWithSpeakers(1);
        $this->fakeCloudflare([['tracks' => [self::MISSING]], ['tracks' => [self::MISSING]]]);
        $version = Room::find($roomId)->version;

        $this->subscribe($listener, $roomId)->assertOk();
        $this->assertTrue($this->memberOf($speakers[0])->sfu_publisher_ready, 'one report could be a passing hiccup');

        $this->subscribe($listener, $roomId, ['session_id' => 'recv-1', 'spare' => true])->assertOk();
        $speaker = $this->memberOf($speakers[0]);
        $this->assertFalse($speaker->sfu_publisher_ready);
        $this->assertNull($speaker->sfu_publisher_session_id);
        $this->assertGreaterThan($version, Room::find($roomId)->version);
        $this->sync($listener, $roomId)->assertJsonPath('state.hosts.0.sfu_publisher', null);
    }

    public function test_a_republished_speaker_is_not_cleared_by_reports_about_its_old_session(): void
    {
        [$roomId, $speakers, $listener] = $this->roomWithSpeakers(1);
        $this->fakeCloudflare([['tracks' => [self::MISSING]], ['tracks' => [self::MISSING]]]);

        $this->subscribe($listener, $roomId)->assertOk();
        $this->publish($speakers[0], 'pub-new');
        $this->subscribe($listener, $roomId, ['session_id' => 'recv-1', 'spare' => true])->assertOk();

        $this->assertTrue($this->memberOf($speakers[0])->sfu_publisher_ready);
    }

    public function test_routine_track_errors_log_quietly_and_others_warn(): void
    {
        [$roomId, , $listener] = $this->roomWithSpeakers(2);
        $this->fakeCloudflare([
            ['tracks' => [['mid' => '0'], self::EMPTY], 'sessionDescription' => self::OFFER],
            ['tracks' => [['mid' => '0'], ['errorCode' => 'track_limit_error']], 'sessionDescription' => self::OFFER],
        ]);
        Log::spy();

        $this->subscribe($listener, $roomId)->assertOk();
        Log::shouldHaveReceived('log')->with('info', 'creators-audio-rooms: Cloudflare SFU subscription returned incomplete tracks', \Mockery::any())->once();

        $this->memberOf($listener)->forceFill(['sfu_receiver_session_id' => null])->save();
        $this->subscribe($listener, $roomId)->assertOk();
        Log::shouldHaveReceived('log')->with('warning', 'creators-audio-rooms: Cloudflare SFU subscription returned incomplete tracks', \Mockery::any())->once();
    }

    /** @return array{0: string, 1: list<Authenticatable>, 2: Authenticatable} room id, published speakers (host first), a listener */
    private function roomWithSpeakers(int $count): array
    {
        $host = $this->staff();
        $roomId = $this->createLiveRoom($host);
        $speakers = [$host];
        for ($i = 1; $i < $count; $i++) {
            $speaker = $this->member();
            $this->join($speaker, $roomId)->assertOk();
            $this->makeSpeaker($host, $roomId, $speaker)->assertOk();
            $speakers[] = $speaker;
        }
        foreach ($speakers as $i => $speaker) {
            $this->publish($speaker, 'pub-'.$i);
        }
        $listener = $this->member();
        $this->join($listener, $roomId)->assertOk();

        return [$roomId, $speakers, $listener];
    }

    private function publish(Authenticatable $who, string $sessionId): void
    {
        $this->memberOf($who)->forceFill([
            'sfu_publisher_session_id' => $sessionId,
            'sfu_publisher_mid' => '0',
            'sfu_publisher_ready' => true,
        ])->save();
    }

    private function subscribe(Authenticatable $who, string $roomId, array $extra = []): TestResponse
    {
        $publishers = Member::query()->where('room_id', $roomId)->where('sfu_publisher_ready', true)->pluck('participant_key')->all();

        return $this->api($who, 'POST', "/rooms/{$roomId}/sfu/subscribe", $extra + ['publishers' => $publishers ?: ['nobody']]);
    }

    /** Cloudflare hands out recv-1, recv-2… for new sessions and answers track requests in the given order. */
    private function fakeCloudflare(array $trackResponses): void
    {
        $sessions = Http::sequence();
        foreach (range(1, 5) as $n) {
            $sessions->push(['sessionId' => 'recv-'.$n]);
        }
        $tracks = Http::sequence();
        foreach ($trackResponses as $response) {
            is_array($response) ? $tracks->push($response) : $tracks->pushResponse($response);
        }
        Http::fake(['*/sessions/new' => $sessions, '*/tracks/new' => $tracks]);
    }

    private function sentTo(string $suffix): int
    {
        return Http::recorded(fn (Request $request) => str_ends_with($request->url(), $suffix))->count();
    }

    private function memberOf(Authenticatable $who): Member
    {
        return Member::query()->where('participant_key', $this->keyOf($who))->firstOrFail();
    }
}
