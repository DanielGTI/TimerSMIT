<?php

use App\Http\Controllers\ActivityTypeController;
use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\MeController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TimeEntryController;
use App\Http\Controllers\TimerController;
use App\Http\Controllers\TimesheetController;
use Illuminate\Support\Facades\Route;

// Saúde para monitor externo: confere o banco, sem autenticação nem detalhes.
Route::get('/health', [HealthController::class, 'show'])->middleware('throttle:60,1');

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

    Route::get('/reports/options', [ReportController::class, 'options']);
    Route::get('/reports/time', [ReportController::class, 'show']);
    Route::get('/reports/time/detail', [ReportController::class, 'detail']);
    Route::get('/reports/time.csv', [ReportController::class, 'csv']);

    Route::middleware('admin')->prefix('settings')->group(function () {
        Route::get('/', [SettingsController::class, 'show']);
        Route::put('/organization', [SettingsController::class, 'updateOrganization']);
        Route::put('/policy', [SettingsController::class, 'updatePolicy']);
        Route::patch('/projects/{projectId}', [SettingsController::class, 'updateProject'])->whereNumber('projectId');
        Route::post('/activity-types', [SettingsController::class, 'storeActivityType']);
        Route::patch('/activity-types/{typeId}', [SettingsController::class, 'updateActivityType'])->whereNumber('typeId');
        Route::post('/people/sync', [SettingsController::class, 'syncPeople']);
        Route::post('/role-assignments', [SettingsController::class, 'grantRole']);
        Route::delete('/role-assignments/{assignmentId}', [SettingsController::class, 'revokeRole'])->whereNumber('assignmentId');
        Route::post('/approver-assignments', [SettingsController::class, 'designateApprover']);
        Route::delete('/approver-assignments/{assignmentId}', [SettingsController::class, 'removeDesignation'])->whereNumber('assignmentId');
    });

    Route::post('/entries', [TimeEntryController::class, 'store']);
    Route::patch('/entries/{entryId}', [TimeEntryController::class, 'update']);
    Route::delete('/entries/{entryId}', [TimeEntryController::class, 'destroy']);
});
