<?php

namespace App\Http\Controllers;

use App\Models\ApprovalDecision;
use App\Services\ApprovalService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Caixa de aprovações (US3, T028). Quem pode ver/decidir o quê é decidido em
 * ApprovalService; aqui só entrada/saída HTTP.
 */
class ApprovalController extends Controller
{
    public function __construct(private readonly ApprovalService $approvals) {}

    public function index(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $view = $request->validate([
            'view' => ['nullable', Rule::in(['pending', 'decided'])],
        ])['view'] ?? 'pending';

        $tenant = $tenantContext->tenant();
        $member = $tenantContext->member();

        return response()->json(
            $view === 'decided'
                ? $this->approvals->decidedByMe($tenant, $member)
                : $this->approvals->pending($tenant, $member),
        );
    }

    public function show(TenantContext $tenantContext, int $submissionId): JsonResponse
    {
        return response()->json($this->approvals->detail(
            $tenantContext->tenant(),
            $tenantContext->member(),
            $submissionId,
        ));
    }

    public function decide(Request $request, TenantContext $tenantContext, int $submissionId): JsonResponse
    {
        $key = $this->idempotencyKey($request);

        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'reason' => ['nullable', 'string', 'max:2000'],
            'revision' => ['nullable', 'integer', 'min:1'],
            // Horas adicionais que o aprovador não autoriza (só vale ao aprovar).
            'unauthorized' => ['nullable', 'array', 'max:200'],
            'unauthorized.*.entryId' => ['required', 'integer', 'min:1'],
            'unauthorized.*.reason' => ['required', 'string', 'max:500'],
        ]);

        $tenant = $tenantContext->tenant();
        $member = $tenantContext->member();

        $submission = $this->approvals->decide(
            tenant: $tenant,
            decider: $member,
            submissionId: $submissionId,
            decision: $data['decision'] === 'approve' ? ApprovalDecision::APPROVED : ApprovalDecision::REJECTED,
            reason: $data['reason'] ?? null,
            expectedRevision: isset($data['revision']) ? (int) $data['revision'] : null,
            idempotencyKey: $key,
            unauthorized: $data['unauthorized'] ?? [],
        );

        return response()->json($this->approvals->present($tenant, $member, $submission));
    }

    public function reopen(Request $request, TenantContext $tenantContext, int $submissionId): JsonResponse
    {
        $key = $this->idempotencyKey($request);

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $tenant = $tenantContext->tenant();
        $member = $tenantContext->member();

        $submission = $this->approvals->reopen($tenant, $member, $submissionId, $data['reason'] ?? null, $key);

        return response()->json($this->approvals->present($tenant, $member, $submission));
    }

    private function idempotencyKey(Request $request): string
    {
        $key = $request->header('Idempotency-Key');

        if (! is_string($key) || strlen($key) < 16 || strlen($key) > 128) {
            throw ValidationException::withMessages([
                'Idempotency-Key' => 'Cabeçalho Idempotency-Key ausente ou fora do tamanho esperado (16-128).',
            ]);
        }

        return $key;
    }
}
