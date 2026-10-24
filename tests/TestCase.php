<?php

namespace daacreators\CreatorsAudioRooms\Tests;

use daacreators\CreatorsAudioRooms\CreatorsAudioRoomsServiceProvider;
use daacreators\CreatorsAudioRooms\Tests\Fixtures\Admin;
use daacreators\CreatorsAudioRooms\Tests\Fixtures\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Package tests run in an empty Laravel app with two unrelated user tables
 * ("users" and "admins") to prove nothing depends on a specific host.
 */
abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [CreatorsAudioRoomsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        $app['config']->set('auth.providers.users', ['driver' => 'eloquent', 'model' => User::class]);
        $app['config']->set('auth.providers.admins', ['driver' => 'eloquent', 'model' => Admin::class]);
        $app['config']->set('auth.guards.web', ['driver' => 'session', 'provider' => 'users']);
        $app['config']->set('auth.guards.admin', ['driver' => 'session', 'provider' => 'admins']);

        $app['config']->set('creators-audio-rooms.guards', [
            'admin' => ['type' => 'staff', 'name_attribute' => 'full_name'],
            'web' => ['type' => 'user', 'name_attribute' => 'name'],
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        // A second user table with its own numbering, as in apps with separate staff accounts.
        Schema::create('admins', function (Blueprint $table) {
            $table->id();
            $table->string('full_name');
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }
}
