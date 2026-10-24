<?php

namespace daacreators\CreatorsAudioRooms\Signaling;

use daacreators\CreatorsAudioRooms\Enums\MemberState;
use daacreators\CreatorsAudioRooms\Exceptions\RoomActionDenied;
use daacreators\CreatorsAudioRooms\Models\Member;
use daacreators\CreatorsAudioRooms\Models\Room;

/**
 * Relays WebRTC connection setup between two active members of the same room.
 *
 * Topology rules (enforced here, not just in the browser):
 * - a connection only exists between a speaker and someone else;
 * - only a speaker may start one (send an offer);
 * - a listener's answer must be receive-only, so listeners can never send audio.
 */
class SignalRelay
{
    /** "candidates" carries several ICE candidates in one request (a JSON list). */
    public const TYPES = ['offer', 'answer', 'candidate', 'candidates', 'bye'];

    private const MAX_CANDIDATES_PER_MESSAGE = 30;

    private const MAX_PAYLOAD_BYTES = 20000;

    public function __construct(private readonly SignalStore $store) {}

    /**
     * $payload is the browser's JSON-encoded message, relayed byte for byte.
     * (Sent as one string so request middleware such as TrimStrings cannot
     * alter the SDP inside it, which must keep its trailing line breaks.)
     */
    public function relay(Room $room, Member $from, string $toKey, string $type, ?string $payload): void
    {
        if (! in_array($type, self::TYPES, true)) {
            throw RoomActionDenied::because('invalid_signal', 422);
        }

        $payload ??= 'null';
        $decoded = json_decode($payload, true);
        if (strlen($payload) > self::MAX_PAYLOAD_BYTES || json_last_error() !== JSON_ERROR_NONE) {
            throw RoomActionDenied::because('invalid_signal', 422);
        }
        if ($type === 'candidates' && (! is_array($decoded) || ! array_is_list($decoded) || count($decoded) > self::MAX_CANDIDATES_PER_MESSAGE)) {
            throw RoomActionDenied::because('invalid_signal', 422);
        }

        $to = Member::query()
            ->where('room_id', $room->id)
            ->where('participant_key', $toKey)
            ->where('state', MemberState::Joined->value)
            ->first();
        if (! $to || $to->participant_key === $from->participant_key) {
            // The other side left meanwhile; dropping the message is the correct outcome.
            return;
        }

        if ($type !== 'bye') {
            if (! $from->role->canSpeak() && ! $to->role->canSpeak()) {
                throw RoomActionDenied::because('invalid_signal', 422);
            }
            if ($type === 'offer' && ! $from->role->canSpeak()) {
                throw RoomActionDenied::because('invalid_signal', 422);
            }
            if ($type === 'answer' && ! $from->role->canSpeak() && $this->sendsAudio($decoded)) {
                throw RoomActionDenied::because('invalid_signal', 422);
            }
        }

        $this->store->push($room->id, $to->participant_key, [
            'from' => $from->participant_key,
            // Lets the receiver drop messages from an older connection of the same person.
            'conn' => $from->connectionTag(),
            'type' => $type,
            'payload' => $payload,
        ]);
    }

    /** Does an SDP answer try to send audio (sendrecv / sendonly)? */
    private function sendsAudio(mixed $payload): bool
    {
        $sdp = is_array($payload) ? (string) ($payload['sdp'] ?? '') : '';

        return (bool) preg_match('/^a=(sendrecv|sendonly)\s*$/mi', $sdp);
    }
}
