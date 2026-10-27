<?php

namespace daacreators\CreatorsAudioRooms\Console;

use daacreators\CreatorsAudioRooms\CreatorsAudioRoomsServiceProvider as Provider;
use daacreators\CreatorsAudioRooms\Signaling\IceServers;
use daacreators\CreatorsAudioRooms\Support\Tables;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Checks the installation without revealing secrets: integration classes,
 * tables, cache, HTTPS, limits and ICE servers.
 */
class DoctorCommand extends Command
{
    protected $signature = 'creators-audio-rooms:doctor';

    protected $description = 'Check the creators-audio-rooms setup.';

    private int $failures = 0;

    private int $warnings = 0;

    public function handle(): int
    {
        $this->components->info('creators-audio-rooms doctor');

        $this->checkIntegrations();
        $this->checkTables();
        $this->checkCache();
        $this->checkHttps();
        $this->checkLimits();
        $this->checkIce();
        $this->checkAssets();

        $this->newLine();
        if ($this->failures > 0) {
            $this->components->error($this->failures.' problem(s) found'.($this->warnings ? ', '.$this->warnings.' warning(s)' : '').'.');

            return self::FAILURE;
        }

        $this->components->info('All checks passed'.($this->warnings ? ' with '.$this->warnings.' warning(s)' : '').'.');

        return self::SUCCESS;
    }

    private function checkIntegrations(): void
    {
        foreach (Provider::INTEGRATIONS as $contract => $key) {
            $class = config(Provider::NAME.'.integration.'.$key);
            $label = 'Integration '.$key;
            if (! is_string($class) || ! class_exists($class)) {
                $this->reportFail($label, 'class not found: '.var_export($class, true));

                continue;
            }
            if (! is_subclass_of($class, $contract)) {
                $this->reportFail($label, $class.' does not implement '.class_basename($contract));

                continue;
            }
            $this->reportPass($label, $class);
        }
    }

    private function checkTables(): void
    {
        foreach (['rooms', 'members', 'bans', 'moderationLog'] as $name) {
            $table = Tables::{$name}();
            try {
                $exists = Schema::connection(Tables::connection())->hasTable($table);
            } catch (Throwable $e) {
                $this->reportFail('Table '.$table, 'database unreachable');

                continue;
            }
            $exists ? $this->reportPass('Table '.$table) : $this->reportFail('Table '.$table, 'missing; run php artisan migrate');
        }
    }

    private function checkCache(): void
    {
        $store = config(Provider::NAME.'.signaling.cache_store');
        $driver = config('cache.stores.'.($store ?: config('cache.default')).'.driver');
        $key = 'creators-audio-rooms:doctor:'.Str::random(8);

        try {
            $cache = Cache::store($store ?: null);
            $cache->put($key, 'ok', 10);
            $ok = $cache->get($key) === 'ok';
            $cache->forget($key);
        } catch (Throwable $e) {
            $ok = false;
        }

        if (! $ok) {
            $this->reportFail('Signaling cache', 'cannot read/write the cache store');

            return;
        }
        if ($driver === 'array') {
            $this->reportFail('Signaling cache', 'the "array" driver does not share data between requests');

            return;
        }
        if (in_array($driver, ['file', 'database'], true)) {
            $this->reportWarn('Signaling cache', $driver.' works; Redis or Memcached is recommended in production');

            return;
        }
        $this->reportPass('Signaling cache', (string) $driver);
    }

    private function checkHttps(): void
    {
        $url = (string) config('app.url');
        $host = (string) parse_url($url, PHP_URL_HOST);
        // Browsers treat localhost as secure, so the microphone works there without HTTPS.
        $loopback = in_array($host, ['localhost', '127.0.0.1', '::1'], true) || str_ends_with($host, '.localhost');

        if (str_starts_with($url, 'https://')) {
            $this->reportPass('HTTPS', 'microphones are available');
        } elseif ($loopback) {
            $this->reportPass('HTTPS', $host.' counts as secure for local development; production needs https');
        } elseif (str_ends_with($host, '.test') || str_ends_with($host, '.local')) {
            $this->reportWarn('HTTPS', 'http://'.$host.' will block the microphone; enable SSL locally or use localhost');
        } else {
            $this->reportFail('HTTPS', 'APP_URL is not https; browsers will block the microphone');
        }
    }

    private function checkLimits(): void
    {
        $limits = (array) config(Provider::NAME.'.limits');
        $hosts = (int) ($limits['max_hosts'] ?? 0);
        $speakers = (int) ($limits['max_speakers'] ?? 0);
        $listeners = (int) ($limits['max_listeners'] ?? 0);

        if ($hosts < 1 || $speakers < $hosts || $listeners < 1) {
            $this->reportFail('Room limits', 'need max_hosts >= 1, max_speakers >= max_hosts, max_listeners >= 1');

            return;
        }

        $label = "{$hosts} hosts, {$speakers} voices, {$listeners} listeners";
        // Each speaker uploads one stream per other member in a peer-to-peer mesh.
        $streams = ($speakers - 1) + $listeners;
        $streams > 45
            ? $this->reportWarn('Room limits', $label.' ('.$streams.' streams per speaker is heavy for phones)')
            : $this->reportPass('Room limits', $label);
    }

    private function checkIce(): void
    {
        $servers = (array) config(Provider::NAME.'.ice_servers');
        $urls = collect($servers)->flatMap(fn ($s) => (array) ($s['urls'] ?? []));
        $hasTurn = $urls->contains(fn ($u) => str_starts_with((string) $u, 'turn')) || IceServers::hasTurn();
        if (IceServers::hasTurn()) {
            $urls = $urls->merge((array) config(Provider::NAME.'.turn.urls'));
        }

        if ($urls->isEmpty()) {
            $this->reportWarn('ICE servers', 'none configured; only same-network connections will work');
        } elseif (! $hasTurn) {
            $this->reportWarn('ICE servers', $urls->count().' STUN, no TURN relay: people on mobile networks or strict Wi-Fi will not hear each other (see "TURN relay" in the README)');
        } else {
            $this->reportPass('ICE servers', $urls->count().' configured (credentials not shown)');
        }
    }

    private function checkAssets(): void
    {
        is_file(public_path('vendor/'.Provider::NAME.'/audio-rooms.js'))
            ? $this->reportPass('Browser assets')
            : $this->reportWarn('Browser assets', 'not published; run php artisan creators-audio-rooms:install');
    }

    private function reportPass(string $label, string $detail = ''): void
    {
        $this->components->twoColumnDetail($label, '<fg=green>OK</>'.($detail !== '' ? ' <fg=gray>'.$detail.'</>' : ''));
    }

    private function reportWarn(string $label, string $detail): void
    {
        $this->warnings++;
        $this->components->twoColumnDetail($label, '<fg=yellow>WARN</> <fg=gray>'.$detail.'</>');
    }

    private function reportFail(string $label, string $detail): void
    {
        $this->failures++;
        $this->components->twoColumnDetail($label, '<fg=red>FAIL</> <fg=gray>'.$detail.'</>');
    }
}
