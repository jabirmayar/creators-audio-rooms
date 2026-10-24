<?php

namespace daacreators\CreatorsAudioRooms\Tests;

use daacreators\CreatorsAudioRooms\Contracts\ParticipantResolver;
use daacreators\CreatorsAudioRooms\Support\ParticipantIdentity;
use daacreators\CreatorsAudioRooms\Tests\Fixtures\Admin;
use daacreators\CreatorsAudioRooms\Tests\Fixtures\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

class IdentityTest extends TestCase
{
    public function test_same_numeric_id_in_two_user_tables_gives_different_participants(): void
    {
        $member = User::create(['name' => 'Sam', 'email' => 'sam@example.com']);
        $staff = Admin::create(['full_name' => 'Alex Morgan', 'email' => 'alex@example.com']);
        $this->assertSame($member->id, $staff->id, 'fixture: both tables start at id 1');

        $resolver = app(ParticipantResolver::class);

        Auth::guard('web')->setUser($member);
        $asMember = $resolver->resolve(Request::create('/'));

        Auth::guard('web')->logout();
        Auth::guard('admin')->setUser($staff);
        $asStaff = $resolver->resolve(Request::create('/'));

        $this->assertSame('user:1', $asMember->key);
        $this->assertSame('staff:1', $asStaff->key);
        $this->assertFalse($asMember->is($asStaff));
        $this->assertSame('Alex Morgan', $asStaff->displayName, 'uses the configured name attribute per guard');
    }

    public function test_guards_are_checked_in_configured_order(): void
    {
        $member = User::create(['name' => 'Sam', 'email' => 'sam@example.com']);
        $staff = Admin::create(['full_name' => 'Alex Morgan', 'email' => 'alex@example.com']);
        Auth::guard('web')->setUser($member);
        Auth::guard('admin')->setUser($staff);

        // "admin" is listed first in the test config.
        $this->assertSame('staff:'.$staff->id, app(ParticipantResolver::class)->resolve(Request::create('/'))->key);
    }

    public function test_nobody_signed_in_resolves_to_null(): void
    {
        $this->assertNull(app(ParticipantResolver::class)->resolve(Request::create('/')));
    }

    public function test_find_by_key_returns_the_right_account_or_null(): void
    {
        User::create(['name' => 'Sam', 'email' => 'sam@example.com']);
        Admin::create(['full_name' => 'Alex Morgan', 'email' => 'alex@example.com']);
        $resolver = app(ParticipantResolver::class);

        $this->assertSame('Alex Morgan', $resolver->find('staff:1')?->displayName);
        $this->assertSame('Sam', $resolver->find('user:1')?->displayName);
        $this->assertNull($resolver->find('user:999'), 'deleted accounts resolve to null');
        $this->assertNull($resolver->find('unknown:1'), 'unknown types resolve to null');
    }

    public function test_identifiers_are_strings_not_integers(): void
    {
        $uuid = ParticipantIdentity::make('member', '9f2c1e7a-58b0-4c3e-9d1a-2f6c8e0b7a11', 'Alex');
        $external = ParticipantIdentity::make('oidc', 'auth0|65f0c2', 'Sam');

        $this->assertSame('member:9f2c1e7a-58b0-4c3e-9d1a-2f6c8e0b7a11', $uuid->key);
        $this->assertSame('oidc:auth0|65f0c2', $external->key);
        $this->assertSame('user:007', ParticipantIdentity::make('user', '007', 'Bond')->key, 'leading zeros are kept');
    }

    public function test_invalid_identities_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ParticipantIdentity::make('bad type!', '1', 'X');
    }

    public function test_overlong_keys_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ParticipantIdentity::make('user', str_repeat('a', 200), 'X');
    }

    public function test_blank_display_names_get_a_fallback(): void
    {
        $this->assertSame('Participant', ParticipantIdentity::make('user', '1', '   ')->displayName);
    }
}
