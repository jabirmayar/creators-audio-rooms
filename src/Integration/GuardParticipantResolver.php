<?php

namespace daacreators\CreatorsAudioRooms\Integration;

use daacreators\CreatorsAudioRooms\Contracts\ParticipantResolver;
use daacreators\CreatorsAudioRooms\Support\ParticipantIdentity;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Default resolver: checks the guards listed in config('creators-audio-rooms.guards')
 * in order and turns the first signed-in account into a participant.
 *
 * Each guard has its own participant type, so the same numeric ID in two
 * user tables becomes two different participants.
 */
class GuardParticipantResolver implements ParticipantResolver
{
    public function resolve(Request $request): ?ParticipantIdentity
    {
        foreach ($this->guards() as $guard => $options) {
            $user = Auth::guard($guard)->user();
            if ($user instanceof Authenticatable) {
                return $this->identityFor($user, $options);
            }
        }

        return null;
    }

    public function find(string $participantKey): ?ParticipantIdentity
    {
        [$type, $id] = array_pad(explode(':', $participantKey, 2), 2, '');

        foreach ($this->guards() as $guard => $options) {
            if (($options['type'] ?? $guard) !== $type) {
                continue;
            }

            // Session and token guards expose their user provider; other guards cannot look users up.
            $instance = Auth::guard($guard);
            $provider = method_exists($instance, 'getProvider') ? $instance->getProvider() : null;
            $user = $provider instanceof UserProvider ? $provider->retrieveById($id) : null;

            return $user ? $this->identityFor($user, $options) : null;
        }

        return null;
    }

    /** @param  array<string, mixed>  $options */
    protected function identityFor(Authenticatable $user, array $options): ParticipantIdentity
    {
        $nameAttribute = $options['name_attribute'] ?? 'name';
        $avatarAttribute = $options['avatar_attribute'] ?? null;
        $profileRoute = $options['profile_route'] ?? null;

        return ParticipantIdentity::make(
            $options['type'] ?? 'user',
            (string) $user->getAuthIdentifier(),
            (string) (data_get($user, $nameAttribute) ?? ''),
            [
                'avatar_url' => $avatarAttribute ? data_get($user, $avatarAttribute) : null,
                'profile_url' => $profileRoute ? route($profileRoute, $user->getAuthIdentifier()) : null,
            ],
        );
    }

    /** @return array<string, array<string, mixed>> */
    protected function guards(): array
    {
        return (array) config('creators-audio-rooms.guards', []);
    }
}
