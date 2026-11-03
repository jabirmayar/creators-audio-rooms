<?php

namespace daacreators\CreatorsAudioRooms\Tests\Feature;

use Illuminate\Support\Facades\Blade;

class HostingTest extends ApiTestCase
{
    public function test_a_room_can_be_created_live_in_one_step(): void
    {
        $host = $this->staff();

        $this->api($host, 'POST', '/rooms', ['title' => 'Right now', 'start' => true])
            ->assertCreated()
            ->assertJsonPath('room.status', 'live');
    }

    public function test_handing_the_room_to_a_co_host_frees_the_host_to_start_another(): void
    {
        $host = $this->staff();
        $co = $this->staff('co@org-a.test');
        $roomId = $this->createLiveRoom($host);
        $this->api($host, 'POST', '/rooms', ['title' => 'Second'])->assertStatus(409)->assertJson(['error' => 'too_many_rooms']);

        $this->join($co, $roomId);
        $this->makeCoHost($host, $roomId, $co)->assertOk();
        // Leaving with a co-host present hands the room over.
        $this->api($host, 'POST', "/rooms/{$roomId}/leave")->assertOk();
        $this->sync($co, $roomId)->assertJsonPath('state.me.role', 'host');

        $this->api($host, 'POST', '/rooms', ['title' => 'Second'])->assertCreated();
        // The new host now holds one open room of their own.
        $this->api($co, 'POST', '/rooms', ['title' => 'Third'])->assertStatus(409);
    }

    public function test_live_now_widget_lists_live_rooms_and_hides_when_none(): void
    {
        $viewer = $this->member();
        $this->as($viewer);
        $empty = Blade::render('<x-creators-audio-rooms::live-now />');
        $this->assertStringContainsString('data-audio-rooms-live', $empty);
        $this->assertStringContainsString(' hidden', $empty);

        $this->createLiveRoom($this->staff(), ['title' => 'Town hall']);
        $this->as($viewer);
        $html = Blade::render('<x-creators-audio-rooms::live-now />');
        $this->assertStringContainsString('Town hall', $html);
        $this->assertStringNotContainsString(' hidden', $html);
    }

    public function test_live_now_widget_renders_nothing_for_guests(): void
    {
        auth()->guard('web')->logout();
        auth()->guard('admin')->logout();

        $this->assertSame('', trim(Blade::render('<x-creators-audio-rooms::live-now />')));
    }

    public function test_room_bar_component_loads_its_assets_and_labels_once(): void
    {
        $html = Blade::render('<x-creators-audio-rooms::room-bar /><x-creators-audio-rooms::room-bar />');

        $this->assertSame(1, substr_count($html, 'audio-rooms-bar.js'));
        // The stylesheet is only referenced (loaded by the script when a bar is shown), never linked up front.
        $this->assertStringContainsString('data-css="', $html);
        $this->assertStringNotContainsString('<link rel="stylesheet"', $html);
        $this->assertStringContainsString('data-audio-rooms-bar=', $html);
        $this->assertStringContainsString(e(__('creators-audio-rooms::messages.ui.bar_back')), $html);
    }
}
