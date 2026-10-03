<?php

use App\Http\Controllers\ActivityTypeController;
use App\Http\Controllers\AdditionalHoursController;
use App\Http\Controllers\AdminTimeEntryController;
use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\HourBankController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\MeController;
use App\Http\Controllers\ProjectHoursController;
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
    Route::post('/me/weeks/{weekStartDate}/recall', [TimesheetController::class, 'recall'])
        ->where('weekStartDate', '\d{4}-\d{2}-\d{2}');
    Route::get('/me/months/{month}', [TimesheetController::class, 'month'])
        ->where('month', '\d{4}-(?:0[1-9]|1[0-2])');

    Route::get('/me/hour-bank', [HourBankController::class, 'mine']);

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
        Route::put('/overtime-rules', [SettingsController::class, 'updateOvertimeRules']);
        Route::post('/holidays', [SettingsController::class, 'storeHoliday']);
        Route::post('/holidays/national', [SettingsController::class, 'storeNationalHolidays']);
        Route::delete('/holidays/{holidayId}', [SettingsController::class, 'destroyHoliday'])->whereNumber('holidayId');
        Route::put('/members/{memberId}/hours-regime', [SettingsController::class, 'updateHoursRegime'])->whereNumber('memberId');
        Route::patch('/projects/{projectId}', [SettingsController::class, 'updateProject'])->whereNumber('projectId');
        Route::post('/activity-types', [SettingsController::class, 'storeActivityType']);
        Route::patch('/activity-types/{typeId}', [SettingsController::class, 'updateActivityType'])->whereNumber('typeId');
        Route::post('/people/sync', [SettingsController::class, 'syncPeople']);
        Route::post('/import/7pace', [ImportController::class, 'sevenPace']);
        Route::post('/role-assignments', [SettingsController::class, 'grantRole']);
        Route::delete('/role-assignments/{assignmentId}', [SettingsController::class, 'revokeRole'])->whereNumber('assignmentId');
        Route::post('/approver-assignments', [SettingsController::class, 'designateApprover']);
        Route::delete('/approver-assignments/{assignmentId}', [SettingsController::class, 'removeDesignation'])->whereNumber('assignmentId');
    });

    // Fila do administrador: horas adicionais de semanas aprovadas, a classificar.
    Route::middleware('admin')->prefix('additional-hours')->group(function () {
        Route::get('/', [AdditionalHoursController::class, 'index']);
        Route::post('/classify', [AdditionalHoursController::class, 'classify']);
        Route::get('/closing', [AdditionalHoursController::class, 'closing']);
        Route::get('/closing.csv', [AdditionalHoursController::class, 'closingCsv']);
    });

    // Horas do mês por projeto e pessoa (centro de custo), na tela e em Excel.
    Route::middleware('admin')->group(function () {
        Route::get('/project-hours', [ProjectHoursController::class, 'index']);
        Route::get('/project-hours.xlsx', [ProjectHoursController::class, 'xlsx']);
    });

    // Banco de horas de todos: saldos, extrato, folgas, pagamentos e ajustes.
    Route::middleware('admin')->prefix('hour-bank')->group(function () {
        Route::get('/', [HourBankController::class, 'index']);
        Route::get('/{memberId}', [HourBankController::class, 'show'])->whereNumber('memberId');
        Route::post('/{memberId}/movements', [HourBankController::class, 'store'])->whereNumber('memberId');
        Route::delete('/movements/{movementId}', [HourBankController::class, 'destroy'])->whereNumber('movementId');
    });

    // Correção de lançamentos de qualquer pessoa (relatório detalhado).
    Route::middleware('admin')->prefix('admin/entries')->group(function () {
        Route::patch('/{entryId}', [AdminTimeEntryController::class, 'update'])->whereNumber('entryId');
        Route::delete('/{entryId}', [AdminTimeEntryController::class, 'destroy'])->whereNumber('entryId');
    });

    Route::post('/entries', [TimeEntryController::class, 'store']);
    Route::patch('/entries/{entryId}', [TimeEntryController::class, 'update']);
    Route::delete('/entries/{entryId}', [TimeEntryController::class, 'destroy']);
});
