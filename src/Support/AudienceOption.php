<?php

namespace daacreators\CreatorsAudioRooms\Support;

use InvalidArgumentException;

/**
 * Something a host can start a room for, e.g. a team, a group or a community.
 * "type" and "ref" are opaque to the package; "group" only groups the choices
 * in the picker (e.g. "Teams", "Communities").
 */
final class AudienceOption
{
    public function __construct(
        public readonly string $type,
        public readonly string $ref,
        public readonly string $label,
        public readonly ?string $group = null,
    ) {
        if ($type === '' || str_contains($type, ':') || strlen($type) > 64) {
            throw new InvalidArgumentException('Audience type must be 1-64 characters without ":".');
        }
        if ($ref === '' || strlen($ref) > 191) {
            throw new InvalidArgumentException('Audience reference must be 1-191 characters.');
        }
    }

    public static function make(string $type, string|int $ref, string $label, ?string $group = null): self
    {
        return new self($type, (string) $ref, $label, $group);
    }

    /** "type:ref", the value the picker submits. */
    public function key(): string
    {
        return $this->type.':'.$this->ref;
    }

    /** @return array{key: string, type: string, ref: string, label: string, group: ?string} */
    public function toArray(): array
    {
        return ['key' => $this->key(), 'type' => $this->type, 'ref' => $this->ref, 'label' => $this->label, 'group' => $this->group];
    }
}
