<?php

namespace daacreators\CreatorsAudioRooms\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Thrown when a room action is not allowed or cannot happen in the room's
 * current state. Rendered as a JSON error with a stable code.
 */
class RoomActionDenied extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly int $status = 403,
    ) {
        parent::__construct(__('creators-audio-rooms::messages.errors.'.$reason));
    }

    public static function because(string $reason, int $status = 403): self
    {
        return new self($reason, $status);
    }

    /**
     * An expected answer (not allowed, room full, session expired and similar),
     * not a failure: keep it out of the app's error log.
     */
    public function report(): bool
    {
        return true;
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'error' => $this->reason,
            'message' => $this->getMessage(),
        ], $this->status);
    }
}
