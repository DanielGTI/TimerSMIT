<?php

namespace App\Services;

use App\Exceptions\ConflictException;
use App\Models\Member;
use App\Models\Tenant;
use App\Models\WeeklySubmission;
use App\Support\WeekCalendar;

/**
 * Semana enviada ou aprovada não aceita criar, alterar nem excluir
 * lançamentos (FR-007). Aberta e rejeitada aceitam (FR-004).
 *
 * Toda mutação de lançamento e o envio da semana travam a mesma linha do
 * membro, então "editar" e "enviar" nunca se intercalam: ou a edição entra
 * antes do envio (e vai no snapshot), ou o envio vence e a edição recebe 409.
 * Chamar sempre dentro de uma transação.
 */
class WeekLockGuard
{
    public function lockMember(Member $member): void
    {
        Member::query()->whereKey($member->id)->lockForUpdate()->first();
    }

    /**
     * @param  iterable<string>  $localDates  datas locais 'Y-m-d' que serão tocadas
     */
    public function assertEditable(Tenant $tenant, Member $member, iterable $localDates): void
    {
        $this->lockMember($member);

        $weekStarts = collect($localDates)
            ->map(fn ($date) => WeekCalendar::startOf((string) $date))
            ->unique()
            ->values()
            ->all();

        $locked = WeeklySubmission::query()
            ->where('tenant_id', $tenant->id)
            ->where('member_id', $member->id)
            ->whereIn('week_start_date', $weekStarts)
            ->whereIn('status', WeeklySubmission::LOCKED_STATUSES)
            ->exists();

        if ($locked) {
            throw new ConflictException('Semana enviada ou aprovada: edição bloqueada.');
        }
    }
}
