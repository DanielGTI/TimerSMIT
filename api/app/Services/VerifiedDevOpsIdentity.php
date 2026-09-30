<?php

namespace App\Services;

/**
 * Fatos confirmados criptograficamente pela assinatura do token da
 * extensão — nunca derivados de campos enviados soltos pelo cliente.
 * Não inclui organização: o token não carrega essa informação (ver
 * DevOpsIdentityVerifier).
 */
final class VerifiedDevOpsIdentity
{
    public function __construct(
        public readonly string $identityId,
        public readonly string $aadTenantId,
    ) {}
}
