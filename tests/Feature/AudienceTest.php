<?php

namespace daacreators\CreatorsAudioRooms\Tests\Feature;

use daacreators\CreatorsAudioRooms\Integration\OpenAudienceResolver;
use daacreators\CreatorsAudioRooms\Models\RoomAudience;
use daacreators\CreatorsAudioRooms\Support\ParticipantIdentity;
use daacreators\CreatorsAudioRooms\Tests\Fixtures\TeamAudiences;
use Illuminate\Support\Facades\Gate;

class AudienceTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        TeamAudiences::$memberships = [];
        TeamAudiences::$everyone = [];
        config(['creators-audio-rooms.integration.audience_resolver' => TeamAudiences::class]);
    }

    public function test_the_picker_lists_the_hosts_options_and_whether_everyone_is_allowed(): void
    {
        $lead = $this->staff('lead@org-a.test');
        TeamAudiences::$everyone = [$this->keyOf($lead)];

        $this->api($lead, 'GET', '/audiences')->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('everyone', true)
            ->assertJsonPath('options.0.key', 'team:red')
            ->assertJsonPath('options.2.group', 'Clubs');
        $this->api($this->staff(), 'GET', '/audiences')->assertJsonPath('everyone', false);
    }

    public function test_a_room_can_be_started_for_several_audiences(): void
    {
        $host = $this->staff();
        $roomId = $this->api($host, 'POST', '/rooms', ['title' => 'Teams sync', 'audiences' => ['team:red', 'club:chess'], 'start' => true])
            ->assertCreated()
            ->assertJsonPath('room.audience_label', 'Red team, Chess club')
            ->json('room.id');

        $this->assertSame(['club:chess', 'team:red'], RoomAudience::where('room_id', $roomId)->get()->map->key()->sort()->values()->all());
    }

    public function test_choosing_no_audience_needs_the_everyone_permission(): void
    {
        $this->api($this->staff(), 'POST', '/rooms', ['title' => 'Open room'])
            ->assertStatus(422)->assertJson(['error' => 'audience_required']);

        $lead = $this->staff('lead@org-a.test');
        TeamAudiences::$everyone = [$this->keyOf($lead)];
        $this->api($lead, 'POST', '/rooms', ['title' => 'Open room'])->assertCreated();
    }

    public function test_audiences_must_come_from_the_hosts_options(): void
    {
        $this->api($this->staff(), 'POST', '/rooms', ['title' => 'Sneaky', 'audiences' => ['team:green']])
            ->assertStatus(422)->assertJson(['error' => 'invalid_audience']);
    }

    public function test_only_people_in_a_chosen_audience_can_see_or_join_the_room(): void
    {
        $host = $this->staff();
        $roomId = $this->api($host, 'POST', '/rooms', ['title' => 'Red only', 'audiences' => ['team:red', 'club:chess'], 'start' => true])->json('room.id');
        $this->join($host, $roomId);

        $red = $this->member();
        $chess = $this->member();
        $blue = $this->member();
        TeamAudiences::$memberships = [
            $this->keyOf($red) => ['team:red'],
            $this->keyOf($chess) => ['club:chess'],
            $this->keyOf($blue) => ['team:blue'],
        ];

        $this->join($red, $roomId)->assertOk();
        $this->join($chess, $roomId)->assertOk();

        $this->api($blue, 'GET', "/rooms/{$roomId}")->assertStatus(404);
        $this->join($blue, $roomId)->assertStatus(404);
        $this->as($blue);
        $this->assertSame([], $this->getJson('/audio-rooms/api/rooms')->json('rooms'));
        $this->get('/audio-rooms')->assertOk()->assertDontSee('Red only');

        $this->as($red);
        $this->assertSame(['Red only'], array_column($this->getJson('/audio-rooms/api/rooms')->json('rooms'), 'title'));
    }

    public function test_monitors_see_rooms_for_any_audience(): void
    {
        Gate::define('audio-rooms.monitor', fn ($user, ParticipantIdentity $participant) => $participant->key === 'staff:2');
        $host = $this->staff();
        $roomId = $this->api($host, 'POST', '/rooms', ['title' => 'Blue only', 'audiences' => ['team:blue'], 'start' => true])->json('room.id');

        $admin = $this->staff('admin@org-a.test');
        $this->assertSame('staff:2', $this->keyOf($admin));
        $this->api($admin, 'GET', "/rooms/{$roomId}")->assertOk()->assertJsonPath('room.audiences.0.label', 'Blue team');
    }

    public function test_apps_without_audience_options_keep_rooms_open_and_reject_audiences(): void
    {
        config(['creators-audio-rooms.integration.audience_resolver' => OpenAudienceResolver::class]);
        $host = $this->staff();

        $this->api($host, 'GET', '/audiences')->assertJsonPath('enabled', false);
        $this->api($host, 'POST', '/rooms', ['title' => 'x', 'audiences' => ['team:red']])->assertStatus(422);
        $this->api($host, 'POST', '/rooms', ['title' => 'Open'])->assertCreated();
    }
}
