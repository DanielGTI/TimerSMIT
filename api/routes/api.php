<?php

use App\Http\Controllers\ActivityTypeController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\MeController;
use App\Http\Controllers\TimeEntryController;
use App\Http\Controllers\TimerController;
use Illuminate\Support\Facades\Route;

// Único endpoint público: troca token de app do Azure DevOps por sessão
// própria (T006). Toda rota abaixo dele exige a sessão (middleware `tenant`).
Route::post('/auth/session', [SessionController::class, 'store'])
    ->middleware('throttle:20,1');

Route::middleware('tenant')->group(function () {
    Route::get('/me', [MeController::class, 'show']);

    Route::get('/activity-types', [ActivityTypeController::class, 'index']);

    Route::get('/me/timer', [TimerController::class, 'show']);
    Route::post('/me/timer', [TimerController::class, 'store']);
    Route::post('/me/timer/stop', [TimerController::class, 'stop']);

    Route::post('/entries', [TimeEntryController::class, 'store']);
    Route::patch('/entries/{entryId}', [TimeEntryController::class, 'update']);
    Route::delete('/entries/{entryId}', [TimeEntryController::class, 'destroy']);
});
