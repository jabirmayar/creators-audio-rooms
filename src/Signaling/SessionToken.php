<?php

namespace daacreators\CreatorsAudioRooms\Signaling;

/**
 * A short-lived, signed token issued after a successful join. It binds one
 * browser connection to one room and one participant, and is required (in
 * addition to the normal app session) on every sync and signal request.
 *
 * A newer join replaces the connection id, so an older tab stops working
 * instead of creating a duplicate participant.
 */
final class SessionToken
{
    public function __construct(
        public readonly string $roomId,
        public readonly string $participantKey,
        public readonly string $connectionId,
        public readonly int $expiresAt,
    ) {}

    public static function issue(string $roomId, string $participantKey, string $connectionId): self
    {
        $ttl = max(60, (int) config('creators-audio-rooms.signaling.session_token_ttl_seconds', 300));

        return new self($roomId, $participantKey, $connectionId, now()->getTimestamp() + $ttl);
    }

    public function encode(): string
    {
        $payload = self::base64url(json_encode([
            'r' => $this->roomId,
            'p' => $this->participantKey,
            'c' => $this->connectionId,
            'e' => $this->expiresAt,
        ], JSON_THROW_ON_ERROR));

        return $payload.'.'.self::base64url(hash_hmac('sha256', $payload, self::secret(), true));
    }

    /** Returns null for anything tampered with, malformed or expired. */
    public static function decode(?string $token): ?self
    {
        if (! is_string($token) || substr_count($token, '.') !== 1) {
            return null;
        }

        [$payload, $signature] = explode('.', $token, 2);
        $expected = self::base64url(hash_hmac('sha256', $payload, self::secret(), true));
        if (! hash_equals($expected, $signature)) {
            return null;
        }

        $data = json_decode((string) base64_decode(strtr($payload, '-_', '+/'), true), true);
        if (! is_array($data) || ! isset($data['r'], $data['p'], $data['c'], $data['e'])) {
            return null;
        }
        if ((int) $data['e'] < now()->getTimestamp()) {
            return null;
        }

        return new self((string) $data['r'], (string) $data['p'], (string) $data['c'], (int) $data['e']);
    }

    public function expiresWithin(int $seconds): bool
    {
        return $this->expiresAt - now()->getTimestamp() <= $seconds;
    }

    private static function secret(): string
    {
        $key = (string) config('app.key');
        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7));
        }

        // Derive a package-specific key so tokens cannot be confused with other signed data.
        return hash_hmac('sha256', 'creators-audio-rooms:session', $key, true);
    }

    private static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
