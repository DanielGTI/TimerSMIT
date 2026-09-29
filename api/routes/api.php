<?php

use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\MeController;
use Illuminate\Support\Facades\Route;

// Único endpoint público: troca token de app do Azure DevOps por sessão
// própria (T006). Toda rota abaixo dele exige a sessão (middleware `tenant`).
Route::post('/auth/session', [SessionController::class, 'store'])
    ->middleware('throttle:20,1');

Route::middleware('tenant')->group(function () {
    Route::get('/me', [MeController::class, 'show']);
});
