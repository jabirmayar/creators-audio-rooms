<?php

namespace daacreators\CreatorsAudioRooms\Http\Middleware;

use Closure;
use daacreators\CreatorsAudioRooms\Contracts\ParticipantResolver;
use daacreators\CreatorsAudioRooms\Exceptions\RoomActionDenied;
use daacreators\CreatorsAudioRooms\Http\Controllers\RoomApiController;
use daacreators\CreatorsAudioRooms\Signaling\SessionToken;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates the frequent sync and signal requests with the signed room
 * session token alone, without the app's session or cookies.
 *
 * These requests arrive every second or two from every participant. Keeping
 * them away from the app session means they never rewrite it, so they cannot
 * race with the participant's other requests (file and cookie session drivers
 * have no locking), and they stay cheap. Sign-in is re-checked whenever the
 * short-lived token is renewed through the session-backed GET /session endpoint.
 */
class ResolveRoomSession
{
    public const TOKEN_ATTRIBUTE = 'audio_room_session';

    public function __construct(private readonly ParticipantResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = SessionToken::decode($request->header(RoomApiController::TOKEN_HEADER));
        if (! $token || $token->roomId !== (string) $request->route('room')) {
            throw RoomActionDenied::because('session_expired', 401);
        }

        $participant = $this->resolver->find($token->participantKey);
        if (! $participant) {
            // The account no longer exists (or can no longer use rooms).
            throw RoomActionDenied::because('not_signed_in', 401);
        }

        $request->attributes->set(ResolveParticipant::ATTRIBUTE, $participant);
        $request->attributes->set(self::TOKEN_ATTRIBUTE, $token);

        return $next($request);
    }
}
