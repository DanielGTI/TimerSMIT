<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Verificação para monitor externo (T041). `/up` só prova que o PHP sobe;
 * esta também consulta o banco — o que quebra de fato numa queda do
 * PostgreSQL. Sem autenticação e sem detalhes na resposta.
 */
class HealthController extends Controller
{
    public function show(): JsonResponse
    {
        try {
            DB::select('select 1');
        } catch (Throwable $exception) {
            Log::critical('api.health.database_unreachable', ['exception' => $exception::class]);

            return response()->json(['status' => 'unavailable'], 503);
        }

        return response()->json(['status' => 'ok']);
    }
}
