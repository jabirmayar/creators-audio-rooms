<?php

namespace daacreators\CreatorsAudioRooms\Tests\Feature;

/**
 * Who a room is for is private to its host, co-hosts and creator. Listeners and the room list
 * seen by ordinary members never name the chosen audience.
 */
class AudiencePrivacyTest extends ApiTestCase
{
    public function test_listeners_do_not_see_who_a_room_is_for(): void
    {
        $host = $this->staff();
        $listener = $this->member();
        $roomId = $this->createLiveRoom($host, ['description' => 'Catch-up']);

        $asHost = $this->sync($host, $roomId)->assertOk()->json('state.room');
        $this->assertArrayHasKey('audience_label', $asHost);

        $asListener = $this->join($listener, $roomId)->assertOk()->json('state.room');
        $this->assertNull($asListener['audience_label']);
        $this->assertSame([], $asListener['audiences']);

        $listed = collect($this->api($listener, 'GET', '/rooms')->assertOk()->json('rooms'))->firstWhere('id', $roomId);
        $this->assertNotNull($listed);
        $this->assertNull($listed['audience_label']);
        $this->assertSame([], $listed['audiences']);
    }
}
