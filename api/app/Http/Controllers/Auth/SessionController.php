<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\DevOpsIdentityVerifier;
use App\Services\IdentityProvisioningService;
use App\Services\InvalidDevOpsTokenException;
use App\Services\SessionTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Troca o token de app do Azure DevOps por uma sessão do backend (T006).
 * Este é o único ponto de entrada onde uma identidade "não confiada" ainda
 * circula — a partir daqui, tudo depende do JWT emitido aqui.
 */
class SessionController extends Controller
{
    public function __construct(
        private readonly DevOpsIdentityVerifier $verifier,
        private readonly IdentityProvisioningService $provisioning,
        private readonly SessionTokenService $sessionTokens,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'appToken' => ['required', 'string'],
            'claimedOrganizationId' => ['required', 'string'],
            'claimedOrganizationName' => ['required', 'string'],
            'displayName' => ['nullable', 'string'],
        ]);

        try {
            $identity = $this->verifier->verify($data['appToken']);
        } catch (InvalidDevOpsTokenException $exception) {
            throw ValidationException::withMessages([
                'appToken' => $exception->getMessage(),
            ]);
        }

        // Uso interno: diretório fora da lista não cria organização nem pessoa.
        if (! in_array(strtolower($identity->aadTenantId), config('timersmit.allowed_aad_tenants'), true)) {
            Log::warning('auth.session.directory_not_allowed', ['aadTenantId' => $identity->aadTenantId]);

            return response()->json([
                'message' => 'Esta extensão é de uso interno e não está liberada para a sua organização.',
            ], 403);
        }

        $provisioned = $this->provisioning->resolve(
            $identity,
            $data['claimedOrganizationId'],
            $data['claimedOrganizationName'],
        );

        if (! empty($data['displayName']) && $provisioned->member->display_name !== $data['displayName']) {
            $provisioned->member->update(['display_name' => $data['displayName']]);
        }

        $session = $this->sessionTokens->issue($provisioned->tenant->id, $provisioned->member->id);

        return response()->json([
            'sessionToken' => $session->token,
            'expiresAt' => $session->expiresAt->toIso8601String(),
            'tenantId' => (string) $provisioned->tenant->id,
        ]);
    }
}
