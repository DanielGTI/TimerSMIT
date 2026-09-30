<?php

namespace App\Services;

/**
 * O que um membro pode ver nos relatórios (FR-009): as próprias horas sempre;
 * as de todos nos projetos que gerencia; tudo da organização se for admin ou
 * gestor da organização inteira. Calculado no servidor a cada requisição.
 */
final class ReportScope
{
    public const ALL = 'all';

    public const PROJECTS = 'projects';

    public const SELF = 'self';

    /**
     * @param  list<int>  $managedProjectIds  projetos em que tem papel de gestor/admin
     */
    public function __construct(
        public readonly int $memberId,
        public readonly bool $tenantWide,
        public readonly array $managedProjectIds,
    ) {}

    public function level(): string
    {
        return match (true) {
            $this->tenantWide => self::ALL,
            $this->managedProjectIds !== [] => self::PROJECTS,
            default => self::SELF,
        };
    }

    public function canSeeOthers(): bool
    {
        return $this->level() !== self::SELF;
    }
}
