<?php

namespace daacreators\CreatorsAudioRooms;

use daacreators\CreatorsAudioRooms\Console\DoctorCommand;
use daacreators\CreatorsAudioRooms\Console\ForgetParticipantCommand;
use daacreators\CreatorsAudioRooms\Console\InstallCommand;
use daacreators\CreatorsAudioRooms\Console\PruneCommand;
use daacreators\CreatorsAudioRooms\Contracts\AudienceResolver;
use daacreators\CreatorsAudioRooms\Contracts\Authorizer;
use daacreators\CreatorsAudioRooms\Contracts\Notifier;
use daacreators\CreatorsAudioRooms\Contracts\ParticipantResolver;
use daacreators\CreatorsAudioRooms\Contracts\TenantResolver;
use daacreators\CreatorsAudioRooms\Http\Middleware\ResolveParticipant;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class CreatorsAudioRoomsServiceProvider extends ServiceProvider
{
    public const NAME = 'creators-audio-rooms';

    /** Host-replaceable contracts and the config key naming their implementation. */
    public const INTEGRATIONS = [
        ParticipantResolver::class => 'participant_resolver',
        Authorizer::class => 'authorizer',
        AudienceResolver::class => 'audience_resolver',
        TenantResolver::class => 'tenant_resolver',
        Notifier::class => 'notifier',
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/creators-audio-rooms.php', self::NAME);

        foreach (self::INTEGRATIONS as $contract => $key) {
            $this->app->bind($contract, function ($app) use ($key) {
                return $app->make(config(self::NAME.'.integration.'.$key));
            });
        }
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadTranslationsFrom(__DIR__.'/../lang', self::NAME);
        $this->loadViewsFrom(__DIR__.'/../resources/views', self::NAME);
        Blade::anonymousComponentPath(__DIR__.'/../resources/views/components', self::NAME);

        $this->registerRateLimits();

        if (config(self::NAME.'.routes.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        }

        if ($this->app->runningInConsole()) {
            $this->registerSchedule();
            $this->registerPublishing();
            $this->commands([
                InstallCommand::class,
                DoctorCommand::class,
                PruneCommand::class,
                ForgetParticipantCommand::class,
            ]);
        }
    }

    /** Housekeeping runs from the host's scheduler (php artisan schedule:run / schedule:work). */
    protected function registerSchedule(): void
    {
        if (! config(self::NAME.'.lifecycle.schedule_prune', true)) {
            return;
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('creators-audio-rooms:prune')->everyFiveMinutes()->withoutOverlapping();
        });
    }

    /** Limits are counted per participant (falling back to IP before sign-in). */
    protected function registerRateLimits(): void
    {
        foreach (['sync', 'signal', 'actions', 'create'] as $name) {
            RateLimiter::for(self::NAME.'.'.$name, function (Request $request) use ($name) {
                $who = $request->attributes->get(ResolveParticipant::ATTRIBUTE);
                $perMinute = (int) config(self::NAME.'.rate_limits.'.$name, 60);

                return Limit::perMinute(max(1, $perMinute))
                    ->by($name.':'.($who->key ?? $request->ip()))
                    ->response(fn () => response()->json([
                        'error' => 'too_many_requests',
                        'message' => __(self::NAME.'::messages.errors.too_many_requests'),
                    ], 429));
            });
        }
    }

    protected function registerPublishing(): void
    {
        $this->publishes([
            __DIR__.'/../config/creators-audio-rooms.php' => config_path('creators-audio-rooms.php'),
        ], self::NAME.'-config');

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], self::NAME.'-migrations');

        $this->publishes([
            __DIR__.'/../lang' => $this->app->langPath('vendor/'.self::NAME),
        ], self::NAME.'-translations');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/'.self::NAME),
        ], self::NAME.'-views');

        // Prebuilt browser bundle: no build step needed in the host app.
        $this->publishes([
            __DIR__.'/../resources/dist' => public_path('vendor/'.self::NAME),
        ], self::NAME.'-assets');
    }
}
