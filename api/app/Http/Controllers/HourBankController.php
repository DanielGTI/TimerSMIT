<?php

namespace App\Http\Controllers;

use App\Models\HourBankMovement;
use App\Models\Member;
use App\Services\HourBankService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Banco de horas: a pessoa vê o próprio extrato; o administrador vê o de
 * todos e lança folgas, pagamentos e ajustes (middleware `admin` nas rotas).
 */
class HourBankController extends Controller
{
    /** Teto de um movimento: 500 horas. */
    private const MAX_SECONDS = 500 * 3600;

    public function __construct(
        private readonly HourBankService $bank,
        private readonly TenantContext $tenantContext,
    ) {}

    public function mine(): JsonResponse
    {
        return response()->json($this->bank->statement($this->tenantContext->tenant(), $this->tenantContext->member()));
    }

    public function index(): JsonResponse
    {
        return response()->json(['members' => $this->bank->overview($this->tenantContext->tenant())]);
    }

    public function show(int $memberId): JsonResponse
    {
        return response()->json($this->bank->statement($this->tenantContext->tenant(), $this->member($memberId)));
    }

    public function store(Request $request, int $memberId): JsonResponse
    {
        $key = $request->header('Idempotency-Key');
        if (! is_string($key) || strlen($key) < 16 || strlen($key) > 128) {
            throw ValidationException::withMessages([
                'Idempotency-Key' => 'Cabeçalho Idempotency-Key ausente ou fora do tamanho esperado (16-128).',
            ]);
        }

        $data = $request->validate([
            'kind' => ['required', Rule::in(HourBankMovement::KINDS)],
            'direction' => ['nullable', Rule::in(['credit', 'debit'])],
            'seconds' => ['required', 'integer', 'min:60', 'max:'.self::MAX_SECONDS],
            'localDate' => ['required', 'date_format:Y-m-d'],
            'note' => ['required', 'string', 'max:500'],
        ], [
            'seconds.min' => 'Informe pelo menos 1 minuto.',
            'seconds.max' => 'Use no máximo 500 horas por lançamento.',
            'note.required' => 'Informe o motivo.',
        ]);

        return response()->json($this->bank->addMovement(
            $this->tenantContext->tenant(),
            $this->tenantContext->member(),
            $this->member($memberId)->id,
            $data['kind'],
            $data['kind'] === HourBankMovement::KIND_ADJUSTMENT ? ($data['direction'] ?? 'debit') : 'debit',
            (int) $data['seconds'],
            $data['localDate'],
            $data['note'],
            $key,
        ));
    }

    public function destroy(int $movementId): JsonResponse
    {
        return response()->json($this->bank->removeMovement(
            $this->tenantContext->tenant(),
            $this->tenantContext->member(),
            $movementId,
        ));
    }

    private function member(int $memberId): Member
    {
        return Member::query()
            ->where('tenant_id', $this->tenantContext->tenant()->id)
            ->whereKey($memberId)
            ->firstOrFail();
    }
}
