<?php

namespace App\Http\Controllers;

use App\Services\TimesheetService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Folha semanal do próprio membro (US2, T023): `/me/weeks/*` e `/me/months/*`.
 * Só dados do usuário autenticado — nenhum id de outra pessoa é aceito.
 */
class TimesheetController extends Controller
{
    public function __construct(private readonly TimesheetService $timesheet) {}

    public function week(TenantContext $tenantContext, string $weekStartDate): JsonResponse
    {
        return response()->json($this->timesheet->week(
            $tenantContext->tenant(),
            $tenantContext->member(),
            $weekStartDate,
        ));
    }

    public function month(TenantContext $tenantContext, string $month): JsonResponse
    {
        return response()->json($this->timesheet->month(
            $tenantContext->tenant(),
            $tenantContext->member(),
            $month,
        ));
    }

    public function submit(Request $request, TenantContext $tenantContext, string $weekStartDate): JsonResponse
    {
        $key = $request->header('Idempotency-Key');

        if (! is_string($key) || strlen($key) < 16 || strlen($key) > 128) {
            throw ValidationException::withMessages([
                'Idempotency-Key' => 'Cabeçalho Idempotency-Key ausente ou fora do tamanho esperado (16-128).',
            ]);
        }

        $this->timesheet->submit($tenantContext->tenant(), $tenantContext->member(), $weekStartDate, $key);

        return response()->json($this->timesheet->week(
            $tenantContext->tenant(),
            $tenantContext->member(),
            $weekStartDate,
        ));
    }
}
