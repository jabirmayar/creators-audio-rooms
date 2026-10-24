<?php

namespace daacreators\CreatorsAudioRooms\Signaling;

use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * Short-lived inboxes for WebRTC connection setup (offers, answers, ICE
 * candidates) and presence heartbeats, kept in the cache only.
 *
 * Each recipient has a counter; every message gets its own key with a short
 * TTL. Readers ask for everything after the last sequence number they saw.
 * Increments are atomic in all shared cache stores, so no locks are needed.
 */
class SignalStore
{
    private const PREFIX = 'creators-audio-rooms:';

    public function cache(): Repository
    {
        return Cache::store(config('creators-audio-rooms.signaling.cache_store') ?: null);
    }

    /** @param  array<string, mixed>  $message */
    public function push(string $roomId, string $toKey, array $message): int
    {
        $ttl = $this->ttl();
        $seqKey = $this->seqKey($roomId, $toKey);
        $cache = $this->cache();

        // Make sure the counter exists (and keeps living) before incrementing.
        $cache->add($seqKey, 0, $ttl * 10);
        $seq = (int) $cache->increment($seqKey);

        $cache->put($this->messageKey($roomId, $toKey, $seq), $message + ['seq' => $seq], $ttl);

        return $seq;
    }

    /**
     * Messages after $since, oldest first.
     *
     * @return array{messages: list<array>, cursor: int}
     */
    public function pull(string $roomId, string $toKey, int $since): array
    {
        $cache = $this->cache();
        $latest = (int) $cache->get($this->seqKey($roomId, $toKey), 0);
        $max = (int) config('creators-audio-rooms.signaling.max_messages_per_sync', 50);

        if ($since > $latest) {
            // The counter expired and restarted; start again from the beginning.
            $since = 0;
        }

        $from = max($since + 1, $latest - $max + 1);
        $messages = [];
        for ($seq = $from; $seq <= $latest; $seq++) {
            $message = $cache->get($this->messageKey($roomId, $toKey, $seq));
            if (is_array($message)) {
                $messages[] = $message;
            }
        }

        return ['messages' => $messages, 'cursor' => $latest];
    }

    /** Current cursor, used when a participant (re)joins so old messages are skipped. */
    public function cursor(string $roomId, string $toKey): int
    {
        return (int) $this->cache()->get($this->seqKey($roomId, $toKey), 0);
    }

    public function touchPresence(string $roomId, string $participantKey, string $connectionId, bool $hidden = false): void
    {
        $timeout = $hidden
            ? (int) config('creators-audio-rooms.lifecycle.presence_hidden_timeout_seconds', 150)
            : (int) config('creators-audio-rooms.lifecycle.presence_timeout_seconds', 25);
        $this->cache()->put($this->presenceKey($roomId, $participantKey), [
            'connection' => $connectionId,
            'at' => now()->getTimestamp(),
        ], $timeout);
    }

    /** @return array{connection: string, at: int}|null */
    public function presence(string $roomId, string $participantKey): ?array
    {
        $value = $this->cache()->get($this->presenceKey($roomId, $participantKey));

        return is_array($value) ? $value : null;
    }

    public function forgetPresence(string $roomId, string $participantKey): void
    {
        $this->cache()->forget($this->presenceKey($roomId, $participantKey));
    }

    /**
     * @template T
     *
     * @param  callable(): T  $value
     * @return T
     */
    public function remember(string $name, int $seconds, callable $value): mixed
    {
        return $this->cache()->remember(self::PREFIX.'state:'.$name, $seconds, Closure::fromCallable($value));
    }

    public function forget(string $name): void
    {
        $this->cache()->forget(self::PREFIX.'state:'.$name);
    }

    /** Run $callback at most once per $seconds for this key (cheap periodic work). */
    public function throttle(string $name, int $seconds, callable $callback): void
    {
        if ($this->cache()->add(self::PREFIX.'throttle:'.$name, 1, $seconds)) {
            $callback();
        }
    }

    private function ttl(): int
    {
        return max(10, (int) config('creators-audio-rooms.signaling.message_ttl_seconds', 60));
    }

    private function seqKey(string $roomId, string $toKey): string
    {
        return self::PREFIX.'seq:'.$roomId.':'.sha1($toKey);
    }

    private function messageKey(string $roomId, string $toKey, int $seq): string
    {
        return self::PREFIX.'msg:'.$roomId.':'.sha1($toKey).':'.$seq;
    }

    private function presenceKey(string $roomId, string $participantKey): string
    {
        return self::PREFIX.'presence:'.$roomId.':'.sha1($participantKey);
    }
}
