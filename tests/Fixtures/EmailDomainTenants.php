<?php

namespace daacreators\CreatorsAudioRooms\Tests\Fixtures;

use daacreators\CreatorsAudioRooms\Contracts\TenantResolver;
use daacreators\CreatorsAudioRooms\Support\ParticipantIdentity;

/** Test tenant resolver: the tenant is the domain of the account's email address. */
class EmailDomainTenants implements TenantResolver
{
    public function tenantFor(ParticipantIdentity $participant): ?string
    {
        [$type, $id] = explode(':', $participant->key, 2);
        $model = $type === 'staff' ? Admin::find($id) : User::find($id);

        return $model ? substr(strrchr((string) $model->email, '@'), 1) : null;
    }

    public function canAccessTenant(ParticipantIdentity $participant, string $tenantKey): bool
    {
        return $this->tenantFor($participant) === $tenantKey;
    }
}
