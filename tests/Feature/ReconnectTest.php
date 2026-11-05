<?php

namespace daacreators\CreatorsAudioRooms\Tests\Feature;

use daacreators\CreatorsAudioRooms\Models\Room;
use daacreators\CreatorsAudioRooms\Services\RoomManager;
use daacreators\CreatorsAudioRooms\Signaling\SessionToken;

/**
 * A page that rejoins after a slow spell keeps its connection, so nobody's audio link to it is rebuilt.
 */
class ReconnectTest extends ApiTestCase
{
    public function test_a_page_the_server_gave_up_on_rejoins_with_the_same_connection(): void
    {
        $host = $this->staff();
        $roomId = $this->createLiveRoom($host);
        $member = $this->member();
        $connection = $this->join($member, $roomId)->assertOk()->json('connection');

        // No check-in from the member for longer than the presence timeout; the host's next one tidies the room.
        $this->travel(70)->seconds();
        $this->sync($host, $roomId)->assertOk();
        $this->sync($member, $roomId)->assertStatus(409)->assertJsonPath('error', 'not_a_member');

        // The page still holds its token, so the rejoin hands the same connection back.
        $this->join($member, $roomId)->assertOk()->assertJsonPath('connection', $connection);
        $this->sync($member, $roomId)->assertOk();
    }

    public function test_a_lapsed_token_still_resumes_the_connection(): void
    {
        $host = $this->staff();
        $roomId = $this->createLiveRoom($host);
        $member = $this->member();
        $connection = $this->join($member, $roomId)->assertOk()->json('connection');

        // Past the token's lifetime (300 s) without a renewal.
        $this->travel(400)->seconds();
        $this->sync($member, $roomId)->assertStatus(401)->assertJsonPath('error', 'session_expired');

        $this->join($member, $roomId)->assertOk()->assertJsonPath('connection', $connection);
    }

    public function test_a_fresh_join_gets_a_new_connection(): void
    {
        $host = $this->staff();
        $roomId = $this->createLiveRoom($host);
        $member = $this->member();
        $first = $this->join($member, $roomId)->assertOk()->json('connection');

        unset($this->sessions[$this->keyOf($member)]);
        $this->assertNotSame($first, $this->join($member, $roomId)->assertOk()->json('connection'));
    }

    public function test_an_old_token_cannot_take_back_a_connection_another_tab_replaced(): void
    {
        $host = $this->staff();
        $roomId = $this->createLiveRoom($host);
        $member = $this->member();
        $first = $this->join($member, $roomId)->assertOk()->json('connection');
        $oldToken = $this->sessions[$this->keyOf($member)];

        // A second tab joins without a token and takes over.
        unset($this->sessions[$this->keyOf($member)]);
        $second = $this->join($member, $roomId)->assertOk()->json('connection');
        $this->assertNotSame($first, $second);

        // The first tab, rejoining with its old token, gets a new connection rather than its old one back.
        $this->sessions[$this->keyOf($member)] = $oldToken;
        $third = $this->join($member, $roomId)->assertOk()->json('connection');
        $this->assertNotSame($first, $third);
    }

    public function test_a_removed_member_does_not_resume_a_connection(): void
    {
        $host = $this->staff();
        $roomId = $this->createLiveRoom($host);
        $member = $this->member();
        $first = $this->join($member, $roomId)->assertOk()->json('connection');

        $this->moderate($host, $roomId, 'remove', $member)->assertOk();
        $response = $this->join($member, $roomId);
        // Whether or not a removed person may come back, they never get their old connection.
        $this->assertNotSame($first, $response->json('connection'));
    }

    public function test_an_expired_token_is_read_only_when_asked(): void
    {
        $token = SessionToken::issue('room-1', 'user:1', 'conn-1')->encode();

        $this->travel(400)->seconds();
        $this->assertNull(SessionToken::decode($token));
        $this->assertSame('conn-1', SessionToken::decode($token, allowExpired: true)?->connectionId);
        $this->assertNull(SessionToken::decode($token.'x', allowExpired: true), 'a bad signature is never accepted');
    }

    public function test_a_member_is_kept_for_a_minute_without_check_ins(): void
    {
        $host = $this->staff();
        $roomId = $this->createLiveRoom($host);
        $member = $this->member();
        $this->join($member, $roomId)->assertOk();

        $this->travel(40)->seconds();
        app(RoomManager::class)->reconcile(Room::query()->findOrFail($roomId));
        $this->sync($member, $roomId)->assertOk();
    }
}
