<?php

namespace daacreators\CreatorsAudioRooms\Tests;

use daacreators\CreatorsAudioRooms\Contracts\Authorizer;
use daacreators\CreatorsAudioRooms\Contracts\ParticipantResolver;
use daacreators\CreatorsAudioRooms\Enums\RoomAction;
use daacreators\CreatorsAudioRooms\Support\ParticipantIdentity;
use daacreators\CreatorsAudioRooms\Tests\Fixtures\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class IntegrationBindingTest extends TestCase
{
    public function test_host_apps_can_swap_the_participant_resolver_in_config(): void
    {
        config(['creators-audio-rooms.integration.participant_resolver' => FixedResolver::class]);

        $this->assertSame('sso:abc', app(ParticipantResolver::class)->resolve(Request::create('/'))->key);
    }

    public function test_default_authorizer_allows_by_default(): void
    {
        $who = ParticipantIdentity::make('user', '1', 'Sam');

        $this->assertTrue(app(Authorizer::class)->allows($who, RoomAction::Join));
    }

    public function test_default_authorizer_defers_to_a_gate_when_defined(): void
    {
        $this->actingAs(User::create(['name' => 'Sam', 'email' => 's@example.com']));
        Gate::define('audio-rooms.create', fn ($user, ParticipantIdentity $p) => $p->type === 'staff');

        $authorizer = app(Authorizer::class);

        $this->assertFalse($authorizer->allows(ParticipantIdentity::make('user', '1', 'Sam'), RoomAction::Create));
        $this->assertTrue($authorizer->allows(ParticipantIdentity::make('staff', '1', 'Alex'), RoomAction::Create));
        $this->assertTrue($authorizer->allows(ParticipantIdentity::make('user', '1', 'Sam'), RoomAction::Join), 'abilities without a gate keep the default');
    }

    public function test_doctor_reports_failures_for_a_non_shared_cache(): void
    {
        config(['cache.default' => 'array']);

        $this->artisan('creators-audio-rooms:doctor')
            ->expectsOutputToContain('does not share data between requests')
            ->assertFailed();
    }
}

class FixedResolver implements ParticipantResolver
{
    public function resolve(Request $request): ?ParticipantIdentity
    {
        return ParticipantIdentity::make('sso', 'abc', 'From SSO');
    }

    public function find(string $participantKey): ?ParticipantIdentity
    {
        return null;
    }
}
