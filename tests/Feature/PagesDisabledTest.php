<?php

namespace daacreators\CreatorsAudioRooms\Tests\Feature;

class PagesDisabledTest extends ApiTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('creators-audio-rooms.routes.pages', false);
    }

    public function test_pages_can_be_turned_off_while_the_api_keeps_working(): void
    {
        $this->assertFalse(app('router')->has('audio-rooms.index'));
        $this->assertFalse(app('router')->has('audio-rooms.show'));

        $this->api($this->staff(), 'POST', '/rooms', ['title' => 'API only'])->assertCreated();
    }
}
