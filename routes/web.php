<?php

use daacreators\CreatorsAudioRooms\Http\Controllers\RoomApiController;
use daacreators\CreatorsAudioRooms\Http\Controllers\RoomPageController;
use daacreators\CreatorsAudioRooms\Http\Middleware\ResolveParticipant;
use daacreators\CreatorsAudioRooms\Http\Middleware\ResolveRoomSession;
use Illuminate\Support\Facades\Route;

$routes = config('creators-audio-rooms.routes');

// Frequent connection traffic: authenticated by the signed room session token, without the app session.
Route::middleware(array_merge((array) ($routes['signaling_middleware'] ?? []), [ResolveRoomSession::class]))
    ->prefix(($routes['prefix'] ?? 'audio-rooms').'/api/rooms/{room}')
    ->name(($routes['name'] ?? 'audio-rooms.').'api.')
    ->where(['room' => '[0-9A-Za-z]{26}'])
    ->group(function () {
        Route::get('/sync', [RoomApiController::class, 'sync'])->middleware('throttle:creators-audio-rooms.sync')->name('sync');
        Route::post('/signal', [RoomApiController::class, 'signal'])->middleware('throttle:creators-audio-rooms.signal')->name('signal');
    });

Route::middleware(array_merge((array) ($routes['middleware'] ?? ['web']), [ResolveParticipant::class]))
    ->prefix($routes['prefix'] ?? 'audio-rooms')
    ->name($routes['name'] ?? 'audio-rooms.')
    ->group(function () use ($routes) {
        if ($routes['pages'] ?? true) {
            Route::get('/', [RoomPageController::class, 'index'])->name('index');
            Route::get('/{room}', [RoomPageController::class, 'show'])->name('show')->where('room', '[0-9A-Za-z]{26}');
        }

        Route::prefix('api')->name('api.')->group(function () {
            Route::get('/rooms', [RoomApiController::class, 'index'])->middleware('throttle:creators-audio-rooms.sync')->name('index');
            Route::post('/rooms', [RoomApiController::class, 'store'])->middleware('throttle:creators-audio-rooms.create')->name('store');

            Route::prefix('/rooms/{room}')->where(['room' => '[0-9A-Za-z]{26}'])->group(function () {
                Route::get('/', [RoomApiController::class, 'show'])->middleware('throttle:creators-audio-rooms.actions')->name('show');
                // Read-only (and needs the room token header), so a page with an expired CSRF token can still learn the person signed out.
                Route::get('/session', [RoomApiController::class, 'renewSession'])->middleware('throttle:creators-audio-rooms.actions')->name('session');

                Route::middleware('throttle:creators-audio-rooms.actions')->group(function () {
                    Route::post('/start', [RoomApiController::class, 'start'])->name('start');
                    Route::post('/end', [RoomApiController::class, 'end'])->name('end');
                    Route::post('/join', [RoomApiController::class, 'join'])->name('join');
                    Route::post('/leave', [RoomApiController::class, 'leave'])->name('leave');
                    Route::post('/hand', [RoomApiController::class, 'hand'])->name('hand');
                    Route::post('/mute', [RoomApiController::class, 'mute'])->name('mute');
                    Route::post('/invitation', [RoomApiController::class, 'invitation'])->name('invitation');
                    Route::post('/moderate', [RoomApiController::class, 'moderate'])->name('moderate');
                });
            });
        });
    });
