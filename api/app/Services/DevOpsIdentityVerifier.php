<?php

namespace App\Services;

use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use RuntimeException;
use UnexpectedValueException;

/**
 * Prova técnica de identidade (research.md CL-004 / tasks.md T006) — v2,
 * corrigida após teste real contra a organização smitbr.
 *
 * A primeira versão desta classe chamava `_apis/connectionData` da Azure
 * DevOps com o `appToken`, seguindo uma suposição errada. A documentação
 * oficial (Authenticate and secure web extensions) e o teste real mostraram
 * que `SDK.getAppToken()` NÃO é um token para chamar APIs da Azure DevOps —
 * é um JWT HS256 assinado com um segredo simétrico exclusivo da extensão
 * publicada (obtido no Marketplace: extensão → "Certificate"), para ser
 * validado *localmente*, sem nenhuma chamada de rede.
 *
 * Claims confirmados decodificando um token real emitido para smitbr:
 *   nameid = GUID do usuário (confirmado batendo com webContext.user.id)
 *   tid    = GUID do tenant do Azure AD/Entra (confirmado batendo com a URL
 *            de login exibida quando o token é usado incorretamente)
 *   iss    = "app.vstoken.visualstudio.com"
 *   aud    = GUID da própria extensão (estável entre instalações)
 *
 * Importante: o token NÃO carrega o ID da organização do Azure DevOps —
 * apenas o tenant do Entra. Por isso a organização em si continua sendo
 * uma afirmação do cliente (`claimedOrganizationId`/`claimedOrganizationName`),
 * mas agora subordinada a um tenant Entra criptograficamente verificado:
 * um invasor de outro diretório Entra não consegue forjar dados dentro
 * deste, mesmo que minta sobre o nome da organização (ver
 * IdentityProvisioningService, que trava a associação org↔tenant Entra na
 * primeira vez que aparece).
 */
class DevOpsIdentityVerifier
{
    public function verify(string $appToken): VerifiedDevOpsIdentity
    {
        $secret = config('timersmit.extension_secret');

        if (! $secret) {
            throw new RuntimeException('AZURE_DEVOPS_EXTENSION_SECRET não configurado.');
        }

        // Tolerância a pequeno desvio de relógio entre o emissor (Microsoft)
        // e este servidor — sem isso, um token recém-emitido pode ser
        // rejeitado como "ainda não válido" por poucos segundos de diferença.
        JWT::$leeway = 5;

        try {
            $claims = JWT::decode($appToken, new Key($secret, 'HS256'));
        } catch (ExpiredException|BeforeValidException $exception) {
            throw new InvalidDevOpsTokenException('Token de app fora da janela de validade.', previous: $exception);
        } catch (SignatureInvalidException|UnexpectedValueException $exception) {
            throw new InvalidDevOpsTokenException('Token de app inválido.', previous: $exception);
        }

        $identityId = $claims->nameid ?? null;
        $aadTenantId = $claims->tid ?? null;

        if (! $identityId || ! $aadTenantId) {
            throw new InvalidDevOpsTokenException('Token de app sem claims nameid/tid esperados.');
        }

        return new VerifiedDevOpsIdentity(
            identityId: $identityId,
            aadTenantId: $aadTenantId,
        );
    }
}
