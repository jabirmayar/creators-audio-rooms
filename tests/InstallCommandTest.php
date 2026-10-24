<?php

namespace daacreators\CreatorsAudioRooms\Tests;

use Illuminate\Support\Facades\File;

class InstallCommandTest extends TestCase
{
    private string $config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->config = config_path('creators-audio-rooms.php');
        File::ensureDirectoryExists(dirname($this->config));
        File::delete($this->config);
    }

    protected function tearDown(): void
    {
        File::delete($this->config);
        File::deleteDirectory(public_path('vendor/creators-audio-rooms'));
        parent::tearDown();
    }

    public function test_install_publishes_the_config_when_missing(): void
    {
        $this->artisan('creators-audio-rooms:install')->assertSuccessful();

        $this->assertFileExists($this->config);
        $this->assertFileExists(public_path('vendor/creators-audio-rooms/audio-rooms.js'));
    }

    public function test_an_existing_config_is_kept_even_with_force(): void
    {
        File::put($this->config, "<?php return ['custom' => true];\n");

        $this->artisan('creators-audio-rooms:install', ['--force' => true])->assertSuccessful();

        $this->assertStringContainsString("'custom' => true", File::get($this->config));
        $this->assertFileExists(public_path('vendor/creators-audio-rooms/audio-rooms.js'));
    }

    public function test_reset_config_replaces_it_on_request(): void
    {
        File::put($this->config, "<?php return ['custom' => true];\n");

        $this->artisan('creators-audio-rooms:install', ['--reset-config' => true])->assertSuccessful();

        $this->assertStringNotContainsString("'custom' => true", File::get($this->config));
        $this->assertStringContainsString("'table_prefix'", File::get($this->config));
    }
}
