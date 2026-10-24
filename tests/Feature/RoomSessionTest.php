<?php

namespace daacreators\CreatorsAudioRooms\Tests\Feature;

use daacreators\CreatorsAudioRooms\Http\Controllers\RoomApiController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Sync and signal requests are authenticated by the room session token only,
 * so they never read or rewrite the app session.
 */
class RoomSessionTest extends ApiTestCase
{
    private function signedOut(): void
    {
        Auth::guard('web')->logout();
        Auth::guard('admin')->logout();
    }

    public function test_sync_works_with_the_room_token_alone_and_leaves_the_app_session_alone(): void
    {
        $host = $this->staff();
        $roomId = $this->createLiveRoom($host);
        $token = $this->sessions[$this->keyOf($host)];
        $this->signedOut();

        $response = $this->json('GET', self::API."/rooms/{$roomId}/sync", [], [RoomApiController::TOKEN_HEADER => $token])
            ->assertOk()
            ->assertJsonPath('state.me.key', $this->keyOf($host));

        // No session or CSRF cookie is set by these requests.
        $this->assertSame([], $response->headers->getCookies());
    }

    public function test_signal_needs_no_csrf_token_or_session(): void
    {
        $host = $this->staff();
        $listener = $this->member();
        $roomId = $this->createLiveRoom($host);
        $this->join($listener, $roomId);
        $token = $this->sessions[$this->keyOf($listener)];
        $this->signedOut();

        $this->json('POST', self::API."/rooms/{$roomId}/signal", [
            'to' => $this->keyOf($host), 'type' => 'candidates', 'payload' => json_encode([['candidate' => 'x']]),
        ], [RoomApiController::TOKEN_HEADER => $token])->assertOk();
    }

    public function test_a_token_only_works_for_its_own_room(): void
    {
        $host = $this->staff();
        $roomId = $this->createLiveRoom($host);
        $token = $this->sessions[$this->keyOf($host)];
        $otherRoom = substr(str_repeat('0', 26).'1', -26);

        $this->json('GET', self::API."/rooms/{$otherRoom}/sync", [], [RoomApiController::TOKEN_HEADER => $token])
            ->assertStatus(401)->assertJson(['error' => 'session_expired']);
        $this->json('GET', self::API."/rooms/{$roomId}/sync")
            ->assertStatus(401)->assertJson(['error' => 'session_expired']);
    }

    public function test_renewal_is_requested_near_expiry_and_requires_the_app_sign_in(): void
    {
        $host = $this->staff();
        $roomId = $this->createLiveRoom($host);

        $this->sync($host, $roomId)->assertJsonMissingPath('renew_session');
        $this->travel(200)->seconds();
        $this->sync($host, $roomId)->assertJsonPath('renew_session', true)->assertJsonMissingPath('session');

        // Signed in: a fresh token for the same connection.
        $fresh = $this->api($host, 'GET', "/rooms/{$roomId}/session")->assertOk()->json('session');
        $this->assertNotSame($this->sessions[$this->keyOf($host)], $fresh);
        $this->sessions[$this->keyOf($host)] = $fresh;
        $this->travel(200)->seconds();
        $this->sync($host, $roomId)->assertOk();

        // Signed out of the app: no renewal.
        $token = $fresh;
        $this->signedOut();
        $this->json('GET', self::API."/rooms/{$roomId}/session", [], [RoomApiController::TOKEN_HEADER => $token])
            ->assertStatus(401)->assertJson(['error' => 'not_signed_in']);
    }

    public function test_expected_refusals_are_not_written_to_the_error_log(): void
    {
        Log::spy();
        $roomId = $this->createLiveRoom($this->staff());

        $this->json('GET', self::API."/rooms/{$roomId}/sync")->assertStatus(401);

        Log::shouldNotHaveReceived('error');
    }

    public function test_renewal_is_refused_for_a_replaced_connection(): void
    {
        $host = $this->staff();
        $roomId = $this->createLiveRoom($host);
        $oldTab = $this->sessions[$this->keyOf($host)];
        $this->join($host, $roomId)->assertOk();

        $this->as($host);
        $this->json('GET', self::API."/rooms/{$roomId}/session", [], [RoomApiController::TOKEN_HEADER => $oldTab])
            ->assertStatus(409)->assertJson(['error' => 'replaced']);
    }
}
