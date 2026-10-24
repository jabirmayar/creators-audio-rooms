<?php

namespace daacreators\CreatorsAudioRooms\Console;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'creators-audio-rooms:install
        {--migrate : Run the package migrations now}
        {--reset-config : Replace an existing config/creators-audio-rooms.php with the package default}
        {--force : Kept for compatibility; browser assets are always refreshed and the config is never overwritten without --reset-config}';

    protected $description = 'Publish the creators-audio-rooms config and browser assets, and optionally migrate.';

    public function handle(): int
    {
        $this->components->info('Installing creators-audio-rooms');

        // An existing config holds the app's integration settings: never overwrite it implicitly.
        $hasConfig = is_file(config_path('creators-audio-rooms.php'));
        $resetConfig = (bool) $this->option('reset-config');
        if (! $hasConfig || $resetConfig) {
            $this->callSilently('vendor:publish', ['--tag' => 'creators-audio-rooms-config', '--force' => $resetConfig]);
            $this->components->task($resetConfig && $hasConfig
                ? 'Config replaced with the package default (config/creators-audio-rooms.php)'
                : 'Config published to config/creators-audio-rooms.php');
        } else {
            $this->components->twoColumnDetail('Config', '<fg=gray>kept your config/creators-audio-rooms.php</>');
        }

        $this->callSilently('vendor:publish', ['--tag' => 'creators-audio-rooms-assets', '--force' => true]);
        $this->components->task('Browser assets published to public/vendor/creators-audio-rooms');

        if ($this->option('migrate')) {
            $this->call('migrate');
        }

        $steps = [];
        if (! $this->option('migrate')) {
            $steps[] = 'Run <fg=green>php artisan migrate</> (tables use the "'.config('creators-audio-rooms.table_prefix').'" prefix).';
        }
        $steps[] = 'Review the integration classes in <fg=green>config/creators-audio-rooms.php</> (users, permissions, audience, tenants).';
        $steps[] = 'Check the setup with <fg=green>php artisan creators-audio-rooms:doctor</>.';
        $steps[] = 'After upgrading the package, run <fg=green>php artisan creators-audio-rooms:install</> again to refresh the browser assets (your config is kept).';

        $this->newLine();
        $this->line('  <fg=gray>Next steps</>');
        foreach ($steps as $i => $step) {
            $this->line('  '.($i + 1).'. '.$step);
        }

        return self::SUCCESS;
    }
}
