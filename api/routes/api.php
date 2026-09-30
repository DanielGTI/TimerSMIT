<?php

use App\Http\Controllers\ActivityTypeController;
use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\MeController;
use App\Http\Controllers\TimeEntryController;
use App\Http\Controllers\TimerController;
use App\Http\Controllers\TimesheetController;
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

    Route::get('/me/weeks/{weekStartDate}', [TimesheetController::class, 'week'])
        ->where('weekStartDate', '\d{4}-\d{2}-\d{2}');
    Route::post('/me/weeks/{weekStartDate}/submit', [TimesheetController::class, 'submit'])
        ->where('weekStartDate', '\d{4}-\d{2}-\d{2}');
    Route::get('/me/months/{month}', [TimesheetController::class, 'month'])
        ->where('month', '\d{4}-(?:0[1-9]|1[0-2])');

    Route::get('/approvals', [ApprovalController::class, 'index']);
    Route::get('/approvals/{submissionId}', [ApprovalController::class, 'show'])->whereNumber('submissionId');
    Route::post('/approvals/{submissionId}/decision', [ApprovalController::class, 'decide'])->whereNumber('submissionId');
    Route::post('/approvals/{submissionId}/reopen', [ApprovalController::class, 'reopen'])->whereNumber('submissionId');

    Route::post('/entries', [TimeEntryController::class, 'store']);
    Route::patch('/entries/{entryId}', [TimeEntryController::class, 'update']);
    Route::delete('/entries/{entryId}', [TimeEntryController::class, 'destroy']);
});
