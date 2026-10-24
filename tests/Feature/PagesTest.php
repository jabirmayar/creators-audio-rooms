<?php

namespace daacreators\CreatorsAudioRooms\Tests\Feature;

use daacreators\CreatorsAudioRooms\Tests\Fixtures\EmailDomainTenants;
use Illuminate\Support\Facades\Blade;

class PagesTest extends ApiTestCase
{
    public function test_room_list_shows_rooms_and_the_create_form(): void
    {
        $host = $this->staff();
        $roomId = $this->createLiveRoom($host, ['title' => 'Product roadmap']);

        $this->as($this->member());
        $this->get('/audio-rooms')
            ->assertOk()
            ->assertSee('Product roadmap')
            ->assertSee('data-audio-rooms-create', false)
            ->assertSee('/audio-rooms/'.$roomId, false);
    }

    public function test_room_page_embeds_the_room_with_its_api_and_labels(): void
    {
        $roomId = $this->createLiveRoom($this->staff());

        $this->as($this->member());
        $html = $this->get('/audio-rooms/'.$roomId)->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-audio-room="[^"]*\/audio-rooms\/api\/rooms\/'.$roomId.'/', $html);
        $this->assertStringContainsString('vendor/creators-audio-rooms/audio-rooms.js', $html);
        $this->assertSame(1, substr_count($html, 'audio-rooms.js'), 'assets are included once');
    }

    public function test_rooms_from_another_tenant_are_not_listed_or_viewable(): void
    {
        config(['creators-audio-rooms.integration.tenant_resolver' => EmailDomainTenants::class]);
        $roomId = $this->createLiveRoom($this->staff('a@org-a.test'), ['title' => 'Org A only']);

        $this->as($this->member('b@org-b.test'));
        $this->get('/audio-rooms')->assertOk()->assertDontSee('Org A only');
        $this->get('/audio-rooms/'.$roomId)->assertNotFound();
    }

    public function test_signed_out_visitors_get_401_when_no_login_route_exists(): void
    {
        $this->get('/audio-rooms')->assertStatus(401);
    }

    public function test_the_blade_component_can_be_embedded_anywhere(): void
    {
        $roomId = $this->createLiveRoom($this->staff());

        $html = Blade::render('<x-creators-audio-rooms::room :room="$id" class="my-room" />', ['id' => $roomId]);

        $this->assertStringContainsString('class="car-room my-room"', $html);
        $this->assertStringContainsString('data-audio-room=', $html);
    }
}
