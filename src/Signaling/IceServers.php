<?php

namespace daacreators\CreatorsAudioRooms\Signaling;

use daacreators\CreatorsAudioRooms\Support\ParticipantIdentity;

/**
 * Builds the ICE server list sent to a browser on join.
 *
 * TURN uses short-lived credentials (the shared-secret scheme supported by
 * coturn and most TURN providers): the browser gets a username that embeds an
 * expiry time and a password derived from it, never the secret itself.
 */
class IceServers
{
    /** @return list<array{urls: list<string>, username?: string, credential?: string}> */
    public function forParticipant(ParticipantIdentity $participant): array
    {
        $servers = array_values((array) config('creators-audio-rooms.ice_servers', []));

        $turn = (array) config('creators-audio-rooms.turn', []);
        $urls = array_values(array_filter((array) ($turn['urls'] ?? [])));
        $secret = (string) ($turn['secret'] ?? '');

        if ($urls !== [] && $secret !== '') {
            $ttl = max(300, (int) ($turn['ttl_seconds'] ?? 3600));
            // "expiry:label"; the label is a hash so no participant identifier is exposed to the TURN server.
            $username = (now()->getTimestamp() + $ttl).':'.substr(hash('sha256', $participant->key), 0, 16);
            $servers[] = [
                'urls' => $urls,
                'username' => $username,
                'credential' => base64_encode(hash_hmac('sha1', $username, $secret, true)),
            ];
        }

        return $servers;
    }

    /** Is a TURN relay configured (with short-lived credentials)? */
    public static function hasTurn(): bool
    {
        return array_filter((array) config('creators-audio-rooms.turn.urls', [])) !== []
            && (string) config('creators-audio-rooms.turn.secret', '') !== '';
    }
}
