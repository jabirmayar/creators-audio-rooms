<?php

namespace daacreators\CreatorsAudioRooms\Integration;

use daacreators\CreatorsAudioRooms\Contracts\TenantResolver;
use daacreators\CreatorsAudioRooms\Support\ParticipantIdentity;

/** Default: no tenants. Uses the identity's tenant key if the resolver set one. */
class NoTenantResolver implements TenantResolver
{
    public function tenantFor(ParticipantIdentity $participant): ?string
    {
        return $participant->tenantKey;
    }

    public function canAccessTenant(ParticipantIdentity $participant, string $tenantKey): bool
    {
        return $participant->tenantKey === null || $participant->tenantKey === $tenantKey;
    }
}
