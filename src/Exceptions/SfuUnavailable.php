<?php

namespace daacreators\CreatorsAudioRooms\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;
use Throwable;

/**
 * Cloudflare Realtime could not be reached or answered with a server error (a timeout, a 5xx).
 * Passing, so the browser tries again; the SFU client has already logged a warning.
 */
class SfuUnavailable extends RuntimeException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct(__('creators-audio-rooms::messages.errors.sfu_unavailable'), 0, $previous);
    }

    /** Already logged as a warning by the SFU client: keep it out of the app's error log. */
    public function report(): bool
    {
        return true;
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'error' => 'sfu_unavailable',
            'message' => $this->getMessage(),
        ], 503);
    }
}
