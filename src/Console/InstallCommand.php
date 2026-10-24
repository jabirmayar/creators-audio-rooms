<?php

namespace daacreators\CreatorsAudioRooms\Console;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'creators-audio-rooms:install
        {--force : Overwrite previously published config and assets}
        {--migrate : Run the package migrations now}';

    protected $description = 'Publish the creators-audio-rooms config and browser assets, and optionally migrate.';

    public function handle(): int
    {
        $force = (bool) $this->option('force');

        $this->components->info('Installing creators-audio-rooms');

        $this->callSilently('vendor:publish', ['--tag' => 'creators-audio-rooms-config', '--force' => $force]);
        $this->components->task('Config published to config/creators-audio-rooms.php');

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
        $steps[] = 'After upgrading the package, run <fg=green>php artisan creators-audio-rooms:install --force</> to refresh the browser assets.';

        $this->newLine();
        $this->line('  <fg=gray>Next steps</>');
        foreach ($steps as $i => $step) {
            $this->line('  '.($i + 1).'. '.$step);
        }

        return self::SUCCESS;
    }
}
