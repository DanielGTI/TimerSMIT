<?php

namespace App\Services;

/**
 * Fatos confirmados diretamente pelo Azure DevOps sobre quem está chamando,
 * nunca derivados de campos enviados pelo cliente.
 */
final class VerifiedDevOpsIdentity
{
    public function __construct(
        public readonly string $organizationId,
        public readonly string $organizationName,
        public readonly string $identityId,
        public readonly string $displayName,
    ) {}
}
