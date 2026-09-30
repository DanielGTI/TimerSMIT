<?php

namespace App\Services;

use App\Models\ActivityType;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Tipos de atividade do tenant (US1 — base dos relatórios por atividade).
 * Um tenant novo recebe o conjunto padrão na primeira listagem; depois disso
 * o administrador gerencia os seus (US5). Desabilitar preserva o tipo nos
 * lançamentos históricos, por isso nunca se apaga.
 */
class ActivityTypeService
{
    /** Conjunto inicial (nome => cor), alinhado ao usado hoje no 7pace. */
    private const DEFAULTS = [
        'Banco de Horas' => '#F9EB7A',
        'Deployment' => '#F4B6A6',
        'Desenvolvimento' => '#A6D8F5',
        'Design' => '#A8E0B8',
        'Documentação' => '#D2D2D2',
        'Estudos IA' => '#7CB08A',
        'Planejamento' => '#A5B0D8',
        'Requisitos' => '#C9A0D0',
        'Reunião Cliente' => '#6FA58A',
        'Reunião Interna' => '#F0A0A0',
        'Suporte ao Cliente' => '#F87878',
        'Testes' => '#F8D48C',
    ];

    /**
     * @return Collection<int, ActivityType>
     */
    public function listEnabled(Tenant $tenant): Collection
    {
        $this->ensureDefaults($tenant);

        return ActivityType::query()
            ->where('tenant_id', $tenant->id)
            ->where('is_enabled', true)
            ->orderBy('name')
            ->get();
    }

    /** Só aceita tipos habilitados do próprio tenant — nunca de outro. */
    public static function validIdRule(Tenant $tenant): Exists
    {
        return Rule::exists('activity_types', 'id')
            ->where('tenant_id', $tenant->id)
            ->where('is_enabled', true);
    }

    public function ensureDefaults(Tenant $tenant): void
    {
        if (ActivityType::query()->where('tenant_id', $tenant->id)->exists()) {
            return;
        }

        $now = Date::now();

        // insertOrIgnore: duas primeiras requisições concorrentes não
        // duplicam nem falham (unique tenant_id + name).
        ActivityType::query()->insertOrIgnore(
            collect(self::DEFAULTS)->map(fn (string $color, string $name) => [
                'tenant_id' => $tenant->id,
                'name' => $name,
                'color' => $color,
                'is_enabled' => true,
                // Faturável só por escolha explícita — a definição de quais
                // atividades faturam é regra de negócio do tenant (US5).
                'is_default_billable' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ])->values()->all()
        );
    }
}
