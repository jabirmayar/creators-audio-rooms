<?php

namespace daacreators\CreatorsAudioRooms\Signaling;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Optional TURN relay from Cloudflare (Realtime TURN).
 *
 * Short-lived credentials are requested from Cloudflare's API with the TURN
 * key's API token, which never leaves the server. One set of credentials is
 * shared by all participants and cached for half its lifetime, so joining a
 * room does not call the API each time. If the API cannot be reached, rooms
 * keep working with the other ICE servers and the failure is logged.
 */
class CloudflareTurn
{
    private const ENDPOINT = 'https://rtc.live.cloudflare.com/v1/turn/keys/%s/credentials/generate-ice-servers';

    private const CACHE_KEY = 'creators-audio-rooms:cloudflare-turn';

    public static function configured(): bool
    {
        $config = self::config();

        return ($config['key_id'] ?? '') !== '' && ($config['api_token'] ?? '') !== '';
    }

    /**
     * TURN servers with credentials, or [] when not configured or unavailable.
     *
     * @return list<array{urls: list<string>, username: string, credential: string}>
     */
    public function servers(): array
    {
        if (! self::configured()) {
            return [];
        }

        $cached = $this->cache()->get(self::CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        $config = self::config();
        $ttl = max(600, (int) ($config['ttl_seconds'] ?? 86400));

        try {
            $response = Http::timeout(5)
                ->withToken((string) $config['api_token'])
                ->acceptJson()
                ->post(sprintf(self::ENDPOINT, rawurlencode((string) $config['key_id'])), ['ttl' => $ttl]);

            if (! $response->successful()) {
                throw new \RuntimeException('HTTP '.$response->status());
            }

            $servers = $this->relayServers((array) $response->json('iceServers', []));
            if ($servers === []) {
                throw new \RuntimeException('no TURN servers in the response');
            }
        } catch (Throwable $e) {
            Log::warning('creators-audio-rooms: Cloudflare TURN credentials unavailable', ['error' => $e->getMessage()]);
            // Do not retry on every join while Cloudflare is unreachable or misconfigured.
            $this->cache()->put(self::CACHE_KEY, [], 60);

            return [];
        }

        // Refresh well before expiry so nobody joins with credentials about to lapse.
        $this->cache()->put(self::CACHE_KEY, $servers, intdiv($ttl, 2));

        return $servers;
    }

    /** Forget cached credentials (e.g. after rotating the TURN key). */
    public function flush(): void
    {
        $this->cache()->forget(self::CACHE_KEY);
    }

    /**
     * Keeps entries with credentials (TURN) and drops port 53 URLs, which
     * browsers block and which would only slow connection setup.
     *
     * @param  array<int, mixed>  $iceServers
     * @return list<array{urls: list<string>, username: string, credential: string}>
     */
    private function relayServers(array $iceServers): array
    {
        $servers = [];
        foreach ($iceServers as $server) {
            if (! is_array($server) || empty($server['username']) || empty($server['credential'])) {
                continue;
            }
            $urls = array_values(array_filter(
                (array) ($server['urls'] ?? []),
                fn ($url) => is_string($url) && preg_match('/^turns?:/i', $url) && ! preg_match('/:53(\?|$)/', $url)
            ));
            if ($urls !== []) {
                $servers[] = ['urls' => $urls, 'username' => (string) $server['username'], 'credential' => (string) $server['credential']];
            }
        }

        return $servers;
    }

    /** @return array<string, mixed> */
    private static function config(): array
    {
        return (array) config('creators-audio-rooms.turn.cloudflare', []);
    }

    private function cache(): Repository
    {
        return Cache::store(config('creators-audio-rooms.signaling.cache_store') ?: null);
    }
}
