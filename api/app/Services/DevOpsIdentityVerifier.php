<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Prova técnica de identidade (research.md CL-004 / tasks.md T006).
 *
 * O cliente (extensão) nunca é aceito como fonte de verdade sobre quem é ou
 * a qual organização pertence. O único fato aceito é a resposta que o
 * próprio Azure DevOps devolve ao ser chamado com o token que a extensão
 * recebeu do host: se o token não for válido para a organização informada,
 * a chamada abaixo falha (401/403) e nenhuma sessão é emitida.
 *
 * `$claimedOrganization` é apenas uma dica de roteamento (monta a URL);
 * a organização/identidade reais usadas pelo backend vêm de `instanceId`
 * e `authenticatedUser` na resposta do Azure DevOps, nunca do que o
 * cliente afirmou.
 *
 * Pendência conhecida: os nomes exatos de campo abaixo devem ser
 * reconfirmados contra uma resposta real assim que a organização de teste
 * (passo 3 do quickstart) estiver disponível — ver research.md.
 */
class DevOpsIdentityVerifier
{
    public function verify(string $appToken, string $claimedOrganization): VerifiedDevOpsIdentity
    {
        $organization = $this->sanitizeOrganization($claimedOrganization);

        try {
            $response = Http::withToken($appToken)
                ->acceptJson()
                ->get("https://dev.azure.com/{$organization}/_apis/connectionData", [
                    'connectOptions' => 'includeServices',
                    'api-version' => '7.1',
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Não foi possível contatar o Azure DevOps para validar a identidade.', previous: $exception);
        }

        if ($response->unauthorized() || $response->forbidden()) {
            throw new InvalidDevOpsTokenException('Token de app inválido ou sem acesso à organização informada.');
        }

        if (! $response->successful()) {
            throw new RuntimeException("Azure DevOps retornou status inesperado ({$response->status()}) ao validar identidade.");
        }

        $body = $response->json();

        $organizationId = $body['instanceId'] ?? null;
        $identityId = $body['authenticatedUser']['id'] ?? null;
        $displayName = $body['authenticatedUser']['providerDisplayName']
            ?? $body['authenticatedUser']['customDisplayName']
            ?? null;

        if (! $organizationId || ! $identityId) {
            throw new RuntimeException('Resposta de connectionData sem instanceId/authenticatedUser.id — confirmar contrato na prova técnica.');
        }

        return new VerifiedDevOpsIdentity(
            organizationId: $organizationId,
            organizationName: $organization,
            identityId: $identityId,
            displayName: $displayName ?? $identityId,
        );
    }

    private function sanitizeOrganization(string $claimedOrganization): string
    {
        // Aceita tanto "https://dev.azure.com/{org}/" quanto o nome puro.
        $trimmed = trim($claimedOrganization, "/ \t\n\r\0\x0B");
        $segments = array_values(array_filter(explode('/', $trimmed)));

        return $segments[count($segments) - 1] ?? $trimmed;
    }
}
