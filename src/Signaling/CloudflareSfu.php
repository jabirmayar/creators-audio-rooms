<?php

namespace daacreators\CreatorsAudioRooms\Signaling;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/** Server-side client for Cloudflare Realtime SFU. The App Secret never leaves Laravel. */
class CloudflareSfu
{
    public static function configured(): bool
    {
        $config = (array) config('creators-audio-rooms.sfu.cloudflare', []);

        return filled($config['app_id'] ?? null) && filled($config['app_secret'] ?? null);
    }

    /** @return array<string, mixed> */
    public function createSession(): array
    {
        return $this->request('post', '/sessions/new');
    }

    /** @return array<string, mixed> */
    public function publish(string $sessionId, array $sessionDescription, string $mid, string $trackName = 'audio'): array
    {
        return $this->request('post', '/sessions/'.rawurlencode($sessionId).'/tracks/new', [
            'sessionDescription' => $sessionDescription,
            'tracks' => [['location' => 'local', 'mid' => $mid, 'trackName' => $trackName]],
        ]);
    }

    /** @param list<array{sessionId: string, trackName: string}> $publications
     *  @return array<string, mixed>
     */
    public function subscribe(string $sessionId, array $publications): array
    {
        return $this->request('post', '/sessions/'.rawurlencode($sessionId).'/tracks/new', [
            'tracks' => array_map(static fn (array $publication) => [
                'location' => 'remote',
                'sessionId' => $publication['sessionId'],
                'trackName' => $publication['trackName'],
            ], $publications),
        ]);
    }

    /** @return array<string, mixed> */
    public function renegotiate(string $sessionId, array $sessionDescription): array
    {
        return $this->request('put', '/sessions/'.rawurlencode($sessionId).'/renegotiate', [
            'sessionDescription' => $sessionDescription,
        ]);
    }

    /** Force-close a track when its browser leaves or loses its connection. */
    public function closeTrack(string $sessionId, string $mid): array
    {
        return $this->request('put', '/sessions/'.rawurlencode($sessionId).'/tracks/close', [
            'tracks' => [['mid' => $mid]],
            'force' => true,
        ]);
    }

    /** @return array<string, mixed> */
    private function request(string $method, string $path, ?array $body = null): array
    {
        if (! self::configured()) {
            throw new RuntimeException('Cloudflare Realtime SFU is not configured. Add the SFU App ID and App Secret in Audio Rooms settings.');
        }

        $config = (array) config('creators-audio-rooms.sfu.cloudflare', []);
        $base = rtrim((string) ($config['api_url'] ?? 'https://rtc.live.cloudflare.com/v1'), '/');
        $url = $base.'/apps/'.rawurlencode((string) $config['app_id']).$path;

        try {
            $request = Http::timeout(max(3, (int) ($config['timeout_seconds'] ?? 10)))
                ->withToken((string) $config['app_secret'])
                ->acceptJson();
            $response = $body === null ? $request->{$method}($url) : $request->{$method}($url, $body);
            $data = (array) $response->json();
            if (! $response->successful() || isset($data['errorCode'])) {
                $code = (string) ($data['errorCode'] ?? 'http_'.$response->status());
                Log::warning('creators-audio-rooms: Cloudflare SFU request failed', ['code' => $code, 'status' => $response->status()]);
                throw new RuntimeException('Cloudflare Realtime could not complete the audio connection ('.$code.').');
            }

            return $data;
        } catch (Throwable $e) {
            if ($e instanceof RuntimeException && str_starts_with($e->getMessage(), 'Cloudflare Realtime')) {
                throw $e;
            }
            Log::warning('creators-audio-rooms: Cloudflare SFU request unavailable', ['error' => $e->getMessage()]);
            throw new RuntimeException('Cloudflare Realtime is temporarily unavailable. Please try joining again.', previous: $e);
        }
    }
}
