<?php

namespace daacreators\CreatorsAudioRooms\Http\Controllers;

use daacreators\CreatorsAudioRooms\Exceptions\RoomActionDenied;
use daacreators\CreatorsAudioRooms\Http\Middleware\ResolveParticipant;
use daacreators\CreatorsAudioRooms\Models\Room;
use daacreators\CreatorsAudioRooms\Services\RoomAccess;
use daacreators\CreatorsAudioRooms\Services\RoomManager;
use daacreators\CreatorsAudioRooms\Services\RoomStatePresenter;
use daacreators\CreatorsAudioRooms\Signaling\CloudflareSfu;
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
        private readonly CloudflareSfu $sfu,
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

    /** What the signed-in host may start a room for (drives the "Who can join" picker). */
    public function audiences(Request $request): JsonResponse
    {
        return response()->json($this->presenter->audienceChoices($this->who($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            // Go live right away instead of creating a not-started room.
            'start' => ['sometimes', 'boolean'],
            // "type:ref" keys from GET /api/audiences; empty means everyone (when allowed).
            'audiences' => ['sometimes', 'array', 'max:50'],
            'audiences.*' => ['string', 'max:260'],
        ]);

        $who = $this->who($request);
        $room = $this->rooms->create($who, $data);
        if ($request->boolean('start')) {
            $room = $this->rooms->start($who, $room);
        }
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
        $data = $request->validate(['as' => ['nullable', 'in:monitor']]);
        $who = $this->who($request);
        $room = $this->viewable($who, $room);
        // A page rejoining after its room session lapsed, or after a slow spell made the server treat it as
        // gone, still sends its old token. Signed for this person and room, it lets them keep their connection
        // id, so nobody else's audio link to them is rebuilt.
        $previous = SessionToken::decode($request->header(self::TOKEN_HEADER), allowExpired: true);
        $resume = $previous && $previous->roomId === $room->id && $previous->participantKey === $who->key ? $previous->connectionId : null;
        $transportMode = config('creators-audio-rooms.transport_mode', 'webrtc');
        if ($transportMode === 'sfu' && ! CloudflareSfu::configured()) {
            return response()->json([
                'error' => 'sfu_not_configured',
                'message' => 'Cloudflare Realtime SFU is selected but not configured. An admin must add the SFU App ID and App Secret in Audio Rooms settings.',
            ], 503);
        }
        $joined = $this->rooms->join($who, $room, ($data['as'] ?? null) === 'monitor', $resume);
        if ($resume === null || $resume !== $joined['member']->connection_id) {
            $old = $joined['member'];
            if ($old->sfu_publisher_session_id && $old->sfu_publisher_mid) {
                try {
                    $this->sfu->closeTrack($old->sfu_publisher_session_id, $old->sfu_publisher_mid);
                } catch (\Throwable $e) {
                    report($e);
                }
            }
            $old->forceFill(['sfu_publisher_session_id' => null, 'sfu_publisher_mid' => null, 'sfu_publisher_ready' => false, 'sfu_receiver_session_id' => null])->save();
        }
        $iceServers = $this->iceServers->forParticipant($who);
        // Relay-only needs a relay: if none came back (e.g. Cloudflare unreachable), connect directly instead of not at all.
        $hasRelay = false;
        foreach ($iceServers as $server) {
            foreach ($server['urls'] ?? [] as $url) {
                if (preg_match('/^turns?:/i', $url) === 1) {
                    $hasRelay = true;
                    break 2;
                }
            }
        }

        return response()->json([
            'session' => $joined['token']->encode(),
            'session_expires_at' => $joined['token']->expiresAt,
            'connection' => $joined['member']->connection_id,
            // Start reading signals from now on; anything older belongs to a previous connection.
            'cursor' => $this->signals->cursor($room->id, $who->key),
            'rejoined' => $joined['rejoined'],
            'ice_servers' => $iceServers,
            'transport_mode' => in_array($transportMode, ['webrtc', 'turn', 'sfu'], true) ? $transportMode : 'webrtc',
            'ice_transport_policy' => $hasRelay && ($transportMode === 'turn' || config('creators-audio-rooms.ice_transport_policy', 'all') === 'relay') ? 'relay' : 'all',
            'audio' => ['max_bitrate' => (int) config('creators-audio-rooms.audio.max_bitrate', 32000)],
            'intervals' => [
                'active' => (int) config('creators-audio-rooms.signaling.sync_interval_ms'),
                'idle' => (int) config('creators-audio-rooms.signaling.sync_interval_idle_ms'),
                'hidden' => (int) config('creators-audio-rooms.signaling.sync_interval_hidden_ms'),
                'linking' => (int) config('creators-audio-rooms.signaling.sync_interval_linking_ms', 300),
            ],
            'state' => $this->presenter->forViewer($room->fresh(), $who),
        ]);
    }

    /** Publish this participant's microphone to Cloudflare Realtime SFU. */
    public function sfuPublish(Request $request, string $room): JsonResponse
    {
        abort_unless(config('creators-audio-rooms.transport_mode') === 'sfu', 404);
        $data = $request->validate([
            'session_description' => ['required', 'array'],
            'session_description.type' => ['required', 'in:offer'],
            'session_description.sdp' => ['required', 'string', 'max:200000'],
            'mid' => ['required', 'string', 'max:16'],
        ]);
        $who = $this->who($request);
        $roomModel = $this->viewable($who, $room);
        [$member] = $this->rooms->memberForToken($who, $roomModel, $request->header(self::TOKEN_HEADER));
        if (! $member->role->canSpeak() || $member->moderator_muted) {
            throw RoomActionDenied::because('not_allowed');
        }

        $created = $this->sfu->createSession();
        $sessionId = (string) ($created['sessionId'] ?? '');
        if ($sessionId === '') {
            throw new \RuntimeException('Cloudflare Realtime did not return a publishing session.');
        }
        $published = $this->sfu->publish($sessionId, $data['session_description'], $data['mid']);
        $track = null;
        foreach ($published['tracks'] ?? [] as $candidate) {
            if (is_array($candidate) && empty($candidate['errorCode'])) {
                $track = $candidate;
                break;
            }
        }
        if (! is_array($track) || empty($published['sessionDescription']['sdp'])) {
            throw new \RuntimeException('Cloudflare Realtime could not publish this microphone.');
        }

        $member->forceFill([
            'sfu_publisher_session_id' => $sessionId,
            'sfu_publisher_mid' => $data['mid'],
            'sfu_publisher_ready' => false,
        ])->save();

        return response()->json([
            'session_id' => $sessionId,
            'session_description' => $published['sessionDescription'],
        ]);
    }

    /** Make a publication discoverable only after its WebRTC connection is live. */
    public function sfuPublisherReady(Request $request, string $room): JsonResponse
    {
        abort_unless(config('creators-audio-rooms.transport_mode') === 'sfu', 404);
        $data = $request->validate(['session_id' => ['required', 'string', 'max:128']]);
        $who = $this->who($request);
        $roomModel = $this->viewable($who, $room);
        [$member] = $this->rooms->memberForToken($who, $roomModel, $request->header(self::TOKEN_HEADER));
        if (! $member->role->canSpeak() || $member->moderator_muted
            || ! hash_equals((string) $member->sfu_publisher_session_id, $data['session_id'])) {
            throw RoomActionDenied::because('not_allowed');
        }
        if (! $member->sfu_publisher_ready) {
            $member->forceFill(['sfu_publisher_ready' => true])->save();
            $roomModel->increment('version');
        }

        return response()->json(['ok' => true]);
    }

    /** Subscribe to the room's current, authorized speakers in one SFU receive session. */
    public function sfuSubscribe(Request $request, string $room): JsonResponse
    {
        abort_unless(config('creators-audio-rooms.transport_mode') === 'sfu', 404);
        $data = $request->validate(['publishers' => ['required', 'array', 'min:1', 'max:32'], 'publishers.*' => ['required', 'string', 'max:191']]);
        $who = $this->who($request);
        $roomModel = $this->viewable($who, $room);
        [$member] = $this->rooms->memberForToken($who, $roomModel, $request->header(self::TOKEN_HEADER));
        if ($member->role->value === 'monitor') {
            throw RoomActionDenied::because('not_allowed');
        }

        $publishers = $roomModel->members()
            ->whereIn('participant_key', array_values(array_unique($data['publishers'])))
            ->where('state', 'joined')
            ->whereIn('role', ['host', 'co_host', 'speaker'])
            ->whereNotNull('sfu_publisher_session_id')
            ->where('sfu_publisher_ready', true)
            ->get();
        $publishers = $publishers->reject(fn ($publisher) => $publisher->participant_key === $member->participant_key)->values();
        if ($publishers->isEmpty()) {
            return response()->json(['subscriptions' => [], 'session_description' => null]);
        }

        $created = $this->sfu->createSession();
        $sessionId = (string) ($created['sessionId'] ?? '');
        if ($sessionId === '') {
            throw new \RuntimeException('Cloudflare Realtime did not return a receiving session.');
        }
        $publications = $publishers->map(fn ($publisher) => [
            'sessionId' => $publisher->sfu_publisher_session_id,
            'trackName' => 'audio',
        ])->all();
        $response = $this->sfu->subscribe($sessionId, $publications);
        $subscriptions = [];
        foreach ((array) ($response['tracks'] ?? []) as $index => $track) {
            if (! is_array($track) || ! empty($track['errorCode']) || ! isset($publishers[$index], $track['mid'])) {
                continue;
            }
            $subscriptions[] = ['mid' => (string) $track['mid'], 'key' => $publishers[$index]->participant_key];
        }
        if ($subscriptions === [] || empty($response['sessionDescription']['sdp'])) {
            throw new \RuntimeException('Cloudflare Realtime could not subscribe to the room audio.');
        }

        $member->forceFill(['sfu_receiver_session_id' => $sessionId])->save();

        return response()->json([
            'session_id' => $sessionId,
            'subscriptions' => $subscriptions,
            'session_description' => $response['sessionDescription'],
        ]);
    }

    /** Complete the SFU offer/answer exchange for this participant's receiving session. */
    public function sfuRenegotiate(Request $request, string $room): JsonResponse
    {
        abort_unless(config('creators-audio-rooms.transport_mode') === 'sfu', 404);
        $data = $request->validate([
            'session_id' => ['required', 'string', 'max:128'],
            'session_description' => ['required', 'array'],
            'session_description.type' => ['required', 'in:answer'],
            'session_description.sdp' => ['required', 'string', 'max:200000'],
        ]);
        $who = $this->who($request);
        $roomModel = $this->viewable($who, $room);
        [$member] = $this->rooms->memberForToken($who, $roomModel, $request->header(self::TOKEN_HEADER));
        abort_unless(hash_equals((string) $member->sfu_receiver_session_id, $data['session_id']), 403);

        return response()->json($this->sfu->renegotiate($data['session_id'], $data['session_description']));
    }

    /** Stop exposing a publisher when its speaker leaves or is demoted. */
    public function sfuUnpublish(Request $request, string $room): JsonResponse
    {
        abort_unless(config('creators-audio-rooms.transport_mode') === 'sfu', 404);
        $who = $this->who($request);
        $roomModel = $this->viewable($who, $room);
        [$member] = $this->rooms->memberForToken($who, $roomModel, $request->header(self::TOKEN_HEADER));
        if ($member->sfu_publisher_session_id && $member->sfu_publisher_mid) {
            try {
                $this->sfu->closeTrack($member->sfu_publisher_session_id, $member->sfu_publisher_mid);
            } catch (\Throwable $e) {
                report($e);
            }
            $member->forceFill(['sfu_publisher_session_id' => null, 'sfu_publisher_mid' => null, 'sfu_publisher_ready' => false])->save();
            $roomModel->increment('version');
        }

        return response()->json(['ok' => true]);
    }

    public function leave(Request $request, string $room): JsonResponse
    {
        $who = $this->who($request);
        $room = $this->viewable($who, $room);
        if (config('creators-audio-rooms.transport_mode') === 'sfu') {
            $this->sfuUnpublish($request, $room->id);
            [$member] = $this->rooms->memberForToken($who, $room, $request->header(self::TOKEN_HEADER));
            $member->forceFill(['sfu_receiver_session_id' => null])->save();
        }
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
            // Renewal goes through GET /session, which also confirms the person is still signed in.
            $response['renew_session'] = true;
        }

        return response()->json($response);
    }

    /** A fresh room session token for the current connection; requires the app sign-in. */
    public function renewSession(Request $request, string $room): JsonResponse
    {
        $who = $this->who($request);
        $room = $this->viewable($who, $room);
        [$member] = $this->rooms->memberForToken($who, $room, $request->header(self::TOKEN_HEADER));

        $fresh = SessionToken::issue($room->id, $who->key, (string) $member->connection_id);

        return response()->json([
            'session' => $fresh->encode(),
            'session_expires_at' => $fresh->expiresAt,
        ]);
    }

    public function signal(Request $request, string $room): JsonResponse
    {
        $data = $request->validate([
            'to' => ['required', 'string', 'max:191'],
            'type' => ['required', 'string', 'in:'.implode(',', SignalRelay::TYPES)],
            // JSON-encoded by the browser and relayed untouched.
            'payload' => ['nullable', 'string'],
            // The sender's cursor: the reply then carries the messages waiting for them (one check-in saved).
            'since' => ['nullable', 'integer', 'min:0'],
        ]);

        $who = $this->who($request);
        $room = $this->viewable($who, $room);
        [$member] = $this->rooms->memberForToken($who, $room, $request->header(self::TOKEN_HEADER));

        $this->relay->relay($room, $member, $data['to'], $data['type'], $data['payload'] ?? null);

        $response = ['ok' => true];
        if (isset($data['since'])) {
            $response += $this->signals->pull($room->id, $who->key, (int) $data['since']);
        }

        return response()->json($response);
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
