<?php

namespace daacreators\CreatorsAudioRooms\Http\Controllers;

use daacreators\CreatorsAudioRooms\Exceptions\RoomActionDenied;
use daacreators\CreatorsAudioRooms\Http\Middleware\ResolveParticipant;
use daacreators\CreatorsAudioRooms\Models\Room;
use daacreators\CreatorsAudioRooms\Services\RoomAccess;
use daacreators\CreatorsAudioRooms\Services\RoomManager;
use daacreators\CreatorsAudioRooms\Services\RoomStatePresenter;
use daacreators\CreatorsAudioRooms\Signaling\IceServers;
use daacreators\CreatorsAudioRooms\Signaling\SessionToken;
use daacreators\CreatorsAudioRooms\Signaling\SignalRelay;
use daacreators\CreatorsAudioRooms\Signaling\SignalStore;
use daacreators\CreatorsAudioRooms\Support\ParticipantIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * JSON endpoints used by the browser bundle. Every endpoint re-resolves the
 * participant and re-checks permissions; the UI hiding a button is never
 * relied on.
 */
class RoomApiController extends Controller
{
    public const TOKEN_HEADER = 'X-Audio-Room-Session';

    public function __construct(
        private readonly RoomManager $rooms,
        private readonly RoomAccess $access,
        private readonly RoomStatePresenter $presenter,
        private readonly SignalStore $signals,
        private readonly SignalRelay $relay,
        private readonly IceServers $iceServers,
    ) {}

    /** Rooms the participant can see (the room list page refreshes from this). */
    public function index(Request $request): JsonResponse
    {
        $who = $this->who($request);
        $page = config('creators-audio-rooms.routes.name').'show';
        $hasPages = app('router')->has($page);

        return response()->json([
            'rooms' => array_map(fn (array $room) => $room + [
                'url' => $hasPages ? route($page, $room['id']) : null,
            ], $this->presenter->directory($who)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $room = $this->rooms->create($this->who($request), $data);
        $page = config('creators-audio-rooms.routes.name').'show';

        return response()->json([
            'room' => $this->presenter->summary($room),
            // Null when the ready-made pages are turned off and the host embeds rooms itself.
            'url' => app('router')->has($page) ? route($page, $room) : null,
        ], 201);
    }

    public function show(Request $request, string $room): JsonResponse
    {
        $who = $this->who($request);
        $room = $this->viewable($who, $room);

        return response()->json($this->presenter->forViewer($room, $who));
    }

    public function start(Request $request, string $room): JsonResponse
    {
        $who = $this->who($request);
        $room = $this->rooms->start($who, $this->viewable($who, $room));

        return response()->json($this->presenter->forViewer($room, $who));
    }

    public function end(Request $request, string $room): JsonResponse
    {
        $who = $this->who($request);
        $room = $this->rooms->end($who, $this->viewable($who, $room));

        return response()->json($this->presenter->forViewer($room, $who));
    }

    public function join(Request $request, string $room): JsonResponse
    {
        $who = $this->who($request);
        $room = $this->viewable($who, $room);
        $joined = $this->rooms->join($who, $room);

        return response()->json([
            'session' => $joined['token']->encode(),
            'session_expires_at' => $joined['token']->expiresAt,
            'connection' => $joined['member']->connection_id,
            // Start reading signals from now on; anything older belongs to a previous connection.
            'cursor' => $this->signals->cursor($room->id, $who->key),
            'rejoined' => $joined['rejoined'],
            'ice_servers' => $this->iceServers->forParticipant($who),
            'audio' => ['max_bitrate' => (int) config('creators-audio-rooms.audio.max_bitrate', 32000)],
            'intervals' => [
                'active' => (int) config('creators-audio-rooms.signaling.sync_interval_ms'),
                'idle' => (int) config('creators-audio-rooms.signaling.sync_interval_idle_ms'),
                'hidden' => (int) config('creators-audio-rooms.signaling.sync_interval_hidden_ms'),
            ],
            'state' => $this->presenter->forViewer($room->fresh(), $who),
        ]);
    }

    public function leave(Request $request, string $room): JsonResponse
    {
        $who = $this->who($request);
        $room = $this->viewable($who, $room);
        $this->rooms->leave($who, $room);

        return response()->json(['ok' => true]);
    }

    /**
     * The heartbeat: keeps presence alive, returns new signaling messages and,
     * when the room changed since `v`, the new room state.
     */
    public function sync(Request $request, string $room): JsonResponse
    {
        $who = $this->who($request);
        $room = $this->viewable($who, $room);

        // A removed or ended viewer still gets a final state so the UI can explain why.
        try {
            [$member, $token] = $this->rooms->memberForToken($who, $room, $request->header(self::TOKEN_HEADER));
        } catch (RoomActionDenied $e) {
            if (in_array($e->reason, ['removed', 'room_ended', 'not_a_member'], true)) {
                return response()->json([
                    'error' => $e->reason,
                    'message' => $e->getMessage(),
                    'state' => $this->presenter->forViewer($room->fresh(), $who),
                ], $e->status);
            }
            throw $e;
        }

        $this->rooms->heartbeat($room, $member, $request->boolean('hidden'));
        $room->refresh();

        $pulled = $this->signals->pull($room->id, $who->key, max(0, (int) $request->query('since', 0)));
        $clientVersion = (int) $request->query('v', -1);

        $response = [
            'cursor' => $pulled['cursor'],
            'messages' => $pulled['messages'],
            'version' => $room->version,
        ];
        if ($clientVersion !== $room->version) {
            $response['state'] = $this->presenter->forViewer($room, $who);
        }
        if ($token->expiresWithin(120)) {
            $fresh = SessionToken::issue($room->id, $who->key, (string) $member->connection_id);
            $response['session'] = $fresh->encode();
            $response['session_expires_at'] = $fresh->expiresAt;
        }

        return response()->json($response);
    }

    public function signal(Request $request, string $room): JsonResponse
    {
        $data = $request->validate([
            'to' => ['required', 'string', 'max:191'],
            'type' => ['required', 'string', 'in:'.implode(',', SignalRelay::TYPES)],
            // JSON-encoded by the browser and relayed untouched.
            'payload' => ['nullable', 'string'],
        ]);

        $who = $this->who($request);
        $room = $this->viewable($who, $room);
        [$member] = $this->rooms->memberForToken($who, $room, $request->header(self::TOKEN_HEADER));

        $this->relay->relay($room, $member, $data['to'], $data['type'], $data['payload'] ?? null);

        return response()->json(['ok' => true]);
    }

    public function hand(Request $request, string $room): JsonResponse
    {
        $data = $request->validate(['raised' => ['required', 'boolean']]);
        $who = $this->who($request);
        $room = $this->viewable($who, $room);
        $this->rooms->raiseHand($who, $room, (bool) $data['raised']);

        return response()->json($this->presenter->forViewer($room->fresh(), $who));
    }

    public function mute(Request $request, string $room): JsonResponse
    {
        $data = $request->validate(['muted' => ['required', 'boolean']]);
        $who = $this->who($request);
        $room = $this->viewable($who, $room);
        $this->rooms->setSelfMuted($who, $room, (bool) $data['muted']);

        return response()->json($this->presenter->forViewer($room->fresh(), $who));
    }

    /** Accept or decline an invitation to speak. */
    public function invitation(Request $request, string $room): JsonResponse
    {
        $data = $request->validate(['accept' => ['required', 'boolean']]);
        $who = $this->who($request);
        $room = $this->viewable($who, $room);
        $this->rooms->respondToInvitation($who, $room, (bool) $data['accept']);

        return response()->json($this->presenter->forViewer($room->fresh(), $who));
    }

    public function moderate(Request $request, string $room): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'string', 'in:'.implode(',', RoomManager::MODERATION_ACTIONS)],
            'target' => ['required', 'string', 'max:191'],
        ]);

        $who = $this->who($request);
        $room = $this->viewable($who, $room);
        $this->rooms->moderate($who, $room, $data['action'], $data['target']);

        return response()->json($this->presenter->forViewer($room->fresh(), $who));
    }

    private function who(Request $request): ParticipantIdentity
    {
        return $request->attributes->get(ResolveParticipant::ATTRIBUTE);
    }

    private function viewable(ParticipantIdentity $who, string $roomId): Room
    {
        $room = Room::query()->find($roomId);
        if (! $room) {
            throw RoomActionDenied::because('room_not_found', 404);
        }
        $this->access->ensureCanView($who, $room);

        return $room;
    }
}
