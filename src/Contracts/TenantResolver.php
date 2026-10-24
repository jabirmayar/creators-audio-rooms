<?php

namespace daacreators\CreatorsAudioRooms\Contracts;

use daacreators\CreatorsAudioRooms\Support\ParticipantIdentity;

/**
 * Optional organisation isolation. When a tenant key is returned, rooms are
 * created inside that tenant and every query, action and signaling request is
 * restricted to it. Return null for apps without tenants (or for people, such
 * as global admins, who work across tenants).
 */
interface TenantResolver
{
    public function tenantFor(ParticipantIdentity $participant): ?string;

    /**
     * May this participant act in rooms of the given tenant?
     * Called for every room access when the room has a tenant key.
     */
    public function canAccessTenant(ParticipantIdentity $participant, string $tenantKey): bool;
}
