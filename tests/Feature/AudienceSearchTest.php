<?php

namespace daacreators\CreatorsAudioRooms\Tests\Feature;

use daacreators\CreatorsAudioRooms\Tests\Fixtures\SearchableTeamAudiences;

class AudienceSearchTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        SearchableTeamAudiences::$memberships = [];
        SearchableTeamAudiences::$everyone = [];
        SearchableTeamAudiences::$people = [];
        config(['creators-audio-rooms.integration.audience_resolver' => SearchableTeamAudiences::class]);
    }

    public function test_the_picker_offers_search_and_the_api_answers_queries(): void
    {
        $host = $this->staff();
        $alice = $this->member('alice@org-a.test');
        SearchableTeamAudiences::$people = [$this->keyOf($alice) => 'Alice Example', 'user:999' => 'Bob Other'];

        $this->api($host, 'GET', '/audiences')->assertOk()->assertJsonPath('search', true);
        $this->api($host, 'GET', '/audiences?q=ali')->assertOk()
            ->assertJsonCount(1, 'options')
            ->assertJsonPath('options.0.label', 'Alice Example')
            ->assertJsonPath('options.0.key', 'person:'.$this->keyOf($alice));
    }

    public function test_a_searched_person_can_be_chosen_and_only_they_can_join(): void
    {
        $host = $this->staff();
        $alice = $this->member('alice2@org-a.test');
        $bob = $this->member('bob2@org-a.test');
        SearchableTeamAudiences::$people = [$this->keyOf($alice) => 'Alice Example'];

        $roomId = $this->api($host, 'POST', '/rooms', ['title' => 'One to one', 'audiences' => ['person:'.$this->keyOf($alice)], 'start' => true])
            ->assertCreated()
            ->assertJsonPath('room.audience_label', 'Alice Example')
            ->json('room.id');

        $this->join($alice, $roomId)->assertOk();
        // Someone outside the audience cannot even see the room.
        $this->join($bob, $roomId)->assertStatus(404);
    }

    public function test_a_key_the_app_does_not_vouch_for_is_rejected(): void
    {
        $host = $this->staff();
        $this->api($host, 'POST', '/rooms', ['title' => 'Nope', 'audiences' => ['person:user:12345']])
            ->assertStatus(422)->assertJson(['error' => 'invalid_audience']);
    }
}
