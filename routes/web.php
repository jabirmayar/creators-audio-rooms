<?php

use daacreators\CreatorsAudioRooms\Http\Controllers\RoomApiController;
use daacreators\CreatorsAudioRooms\Http\Controllers\RoomPageController;
use daacreators\CreatorsAudioRooms\Http\Middleware\ResolveParticipant;
use Illuminate\Support\Facades\Route;

$routes = config('creators-audio-rooms.routes');

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
                Route::get('/sync', [RoomApiController::class, 'sync'])->middleware('throttle:creators-audio-rooms.sync')->name('sync');
                Route::post('/signal', [RoomApiController::class, 'signal'])->middleware('throttle:creators-audio-rooms.signal')->name('signal');

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
