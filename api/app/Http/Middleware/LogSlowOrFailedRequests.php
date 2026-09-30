<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Métrica operacional mínima (T041): uma linha de log para cada requisição
 * que respondeu 5xx ou passou do limite de lentidão (SC-004: 2 s para
 * timer, 3 s para abrir semana). É a base dos alertas por log no Coolify.
 *
 * Registra só método, padrão da rota (`/api/me/weeks/{weekStartDate}`, nunca
 * a URL real), status e duração — sem cabeçalhos, corpo, query string ou
 * identificadores, então não há como vazar token ou dado pessoal.
 */
class LogSlowOrFailedRequests
{
    public const SLOW_THRESHOLD_MS = 2000;

    public function handle(Request $request, Closure $next): Response
    {
        $start = hrtime(true);

        $response = $next($request);

        $milliseconds = (int) round((hrtime(true) - $start) / 1e6);
        $failed = $response->getStatusCode() >= 500;

        if ($failed || $milliseconds >= self::SLOW_THRESHOLD_MS) {
            Log::log($failed ? 'error' : 'warning', $failed ? 'api.request.failed' : 'api.request.slow', [
                'method' => $request->method(),
                'route' => '/'.ltrim((string) $request->route()?->uri(), '/'),
                'status' => $response->getStatusCode(),
                'durationMs' => $milliseconds,
            ]);
        }

        return $response;
    }
}
