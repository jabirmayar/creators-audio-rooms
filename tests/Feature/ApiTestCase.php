<?php

namespace daacreators\CreatorsAudioRooms\Tests\Feature;

use daacreators\CreatorsAudioRooms\Http\Controllers\RoomApiController;
use daacreators\CreatorsAudioRooms\Tests\Fixtures\Admin;
use daacreators\CreatorsAudioRooms\Tests\Fixtures\User;
use daacreators\CreatorsAudioRooms\Tests\TestCase;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;

abstract class ApiTestCase extends TestCase
{
    protected const API = '/audio-rooms/api';

    /** session tokens by participant key */
    protected array $sessions = [];

    protected function staff(string $email = 'alex@org-a.test'): Admin
    {
        return Admin::create(['full_name' => 'Staff '.$email, 'email' => $email]);
    }

    protected function member(?string $email = null): User
    {
        static $n = 0;
        $n++;

        return User::create(['name' => 'Member '.$n, 'email' => $email ?? "member{$n}@org-a.test"]);
    }

    protected function keyOf(Authenticatable $who): string
    {
        return ($who instanceof Admin ? 'staff:' : 'user:').$who->getAuthIdentifier();
    }

    /** Act as exactly one account (a browser only has one signed-in person here). */
    protected function as(Authenticatable $who): static
    {
        Auth::guard('web')->logout();
        Auth::guard('admin')->logout();
        Auth::guard($who instanceof Admin ? 'admin' : 'web')->setUser($who);
        $this->app['auth']->shouldUse($who instanceof Admin ? 'admin' : 'web');

        return $this;
    }

    protected function api(Authenticatable $who, string $method, string $path, array $data = []): TestResponse
    {
        $this->as($who);
        $headers = [];
        if (isset($this->sessions[$this->keyOf($who)])) {
            $headers[RoomApiController::TOKEN_HEADER] = $this->sessions[$this->keyOf($who)];
        }

        return $this->json($method, self::API.$path, $data, $headers);
    }

    protected function createLiveRoom(Admin $host, array $data = []): string
    {
        $id = $this->api($host, 'POST', '/rooms', $data + ['title' => 'Weekly Q&A'])->assertCreated()->json('room.id');
        $this->join($host, $id);
        $this->api($host, 'POST', "/rooms/{$id}/start")->assertOk();

        return $id;
    }

    protected function join(Authenticatable $who, string $roomId): TestResponse
    {
        $response = $this->api($who, 'POST', "/rooms/{$roomId}/join");
        if ($response->isOk()) {
            $this->sessions[$this->keyOf($who)] = $response->json('session');
        }

        return $response;
    }

    protected function sync(Authenticatable $who, string $roomId, int $since = 0, int $version = -1): TestResponse
    {
        return $this->api($who, 'GET', "/rooms/{$roomId}/sync?since={$since}&v={$version}");
    }

    protected function moderate(Authenticatable $who, string $roomId, string $action, Authenticatable $target): TestResponse
    {
        return $this->api($who, 'POST', "/rooms/{$roomId}/moderate", ['action' => $action, 'target' => $this->keyOf($target)]);
    }

    /** A host invites someone to co-host and they accept. */
    protected function makeCoHost(Authenticatable $host, string $roomId, Authenticatable $who): TestResponse
    {
        $this->moderate($host, $roomId, 'make_co_host', $who)->assertOk();

        return $this->api($who, 'POST', "/rooms/{$roomId}/invitation", ['accept' => true]);
    }

    /** The consenting path: the listener asks to speak and a moderator approves. */
    protected function makeSpeaker(Authenticatable $moderator, string $roomId, Authenticatable $listener): TestResponse
    {
        $this->api($listener, 'POST', "/rooms/{$roomId}/hand", ['raised' => true])->assertOk();

        return $this->moderate($moderator, $roomId, 'approve_speaker', $listener);
    }
}
