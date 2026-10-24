<?php

namespace daacreators\CreatorsAudioRooms\Http\Middleware;

use Closure;
use daacreators\CreatorsAudioRooms\Contracts\ParticipantResolver;
use daacreators\CreatorsAudioRooms\Exceptions\RoomActionDenied;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the signed-in participant through the host's ParticipantResolver
 * and makes it available as $request->attributes->get('audio_room_participant').
 */
class ResolveParticipant
{
    public const ATTRIBUTE = 'audio_room_participant';

    public function __construct(private readonly ParticipantResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        $participant = $this->resolver->resolve($request);

        if (! $participant) {
            if ($request->expectsJson()) {
                throw RoomActionDenied::because('not_signed_in', 401);
            }

            $login = config('creators-audio-rooms.routes.login_route');

            return $login && app('router')->has($login)
                ? redirect()->guest(route($login))
                : abort(401, __('creators-audio-rooms::messages.errors.not_signed_in'));
        }

        $request->attributes->set(self::ATTRIBUTE, $participant);

        return $next($request);
    }
}
