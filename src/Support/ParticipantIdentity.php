<?php

namespace daacreators\CreatorsAudioRooms\Support;

use InvalidArgumentException;

/**
 * Who a participant is, as supplied by the host application.
 *
 * The key combines a type and an identifier ("user:5", "admin:5", "staff:9f2c…")
 * so people from different tables or identity providers never collide. The
 * identifier is always treated as a string: integers, UUIDs and external IDs
 * all work the same way.
 */
final class ParticipantIdentity
{
    public const MAX_KEY_LENGTH = 191;

    public readonly string $key;

    /** @param  array<string, mixed>  $attributes */
    public function __construct(
        public readonly string $type,
        public readonly string $id,
        public readonly string $displayName,
        public readonly ?string $avatarUrl = null,
        public readonly ?string $profileUrl = null,
        public readonly ?string $tenantKey = null,
        /** Extra host data for adapters (never stored by the package). */
        public readonly array $attributes = [],
    ) {
        if ($type === '' || ! preg_match('/^[a-z0-9_\-]+$/i', $type)) {
            throw new InvalidArgumentException('Participant type must be letters, numbers, "-" or "_".');
        }
        if ($id === '') {
            throw new InvalidArgumentException('Participant id cannot be empty.');
        }

        $key = $type.':'.$id;
        if (strlen($key) > self::MAX_KEY_LENGTH) {
            throw new InvalidArgumentException('Participant key is longer than '.self::MAX_KEY_LENGTH.' characters.');
        }
        $this->key = $key;
    }

    /**
     * @param  array{avatar_url?: ?string, profile_url?: ?string, tenant_key?: string|int|null, attributes?: array<string, mixed>}  $extra
     */
    public static function make(string $type, string|int $id, string $displayName, array $extra = []): self
    {
        return new self(
            type: $type,
            id: (string) $id,
            displayName: trim($displayName) !== '' ? trim($displayName) : 'Participant',
            avatarUrl: $extra['avatar_url'] ?? null,
            profileUrl: $extra['profile_url'] ?? null,
            tenantKey: isset($extra['tenant_key']) ? (string) $extra['tenant_key'] : null,
            attributes: $extra['attributes'] ?? [],
        );
    }

    public function is(ParticipantIdentity|string|null $other): bool
    {
        if ($other === null) {
            return false;
        }

        return $this->key === ($other instanceof self ? $other->key : $other);
    }

    /**
     * Public, display-safe representation sent to browsers.
     *
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->displayName,
            'avatar_url' => $this->avatarUrl,
            'profile_url' => $this->profileUrl,
        ];
    }
}
