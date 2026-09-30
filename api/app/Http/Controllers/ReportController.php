<?php

namespace App\Http\Controllers;

use App\Models\WeeklySubmission;
use App\Services\AuditService;
use App\Services\TimeReportService;
use App\Support\CsvWriter;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Relatórios e exportação CSV (US4, T032). Tela e CSV usam os mesmos filtros,
 * o mesmo escopo e a mesma consulta (TimeReportService).
 */
class ReportController extends Controller
{
    private const MAX_RANGE_DAYS = 366;

    private const WEEK_STATUS_LABELS = [
        WeeklySubmission::STATUS_OPEN => 'Aberta',
        WeeklySubmission::STATUS_SUBMITTED => 'Enviada',
        WeeklySubmission::STATUS_REJECTED => 'Rejeitada',
        WeeklySubmission::STATUS_APPROVED => 'Aprovada',
    ];

    public function __construct(
        private readonly TimeReportService $reports,
        private readonly AuditService $audit,
    ) {}

    public function options(TenantContext $tenantContext): JsonResponse
    {
        $scope = $this->reports->scopeFor($tenantContext->tenant(), $tenantContext->member());

        return response()->json($this->reports->options($tenantContext->tenant(), $scope));
    }

    public function show(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $tenant = $tenantContext->tenant();
        $filters = $this->filters($request, $tenant->id);
        $paging = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'perPage' => ['nullable', 'integer', 'between:1,200'],
        ]);

        $scope = $this->reports->scopeFor($tenant, $tenantContext->member());
        $this->reports->assertFiltersAllowed($tenant, $scope, $filters);

        return response()->json($this->reports->report(
            $tenant,
            $scope,
            $filters,
            (int) ($paging['page'] ?? 1),
            (int) ($paging['perPage'] ?? 50),
        ));
    }

    public function csv(Request $request, TenantContext $tenantContext): StreamedResponse
    {
        $tenant = $tenantContext->tenant();
        $member = $tenantContext->member();
        $filters = $this->filters($request, $tenant->id);

        $scope = $this->reports->scopeFor($tenant, $member);
        $this->reports->assertFiltersAllowed($tenant, $scope, $filters);

        $export = $this->reports->exportRows($tenant, $scope, $filters);

        $this->audit->record(
            tenant: $tenant,
            action: 'report.exported',
            subjectType: 'TimeReport',
            subjectId: null,
            actor: $member,
            context: [
                'filters' => $filters,
                'scope' => $scope->level(),
                'rowCount' => $export['count'],
                'totalSeconds' => $export['totalSeconds'],
            ],
        );

        return response()->streamDownload(function () use ($export) {
            $out = fopen('php://output', 'w');
            CsvWriter::writeBom($out);
            CsvWriter::writeRow($out, [
                'Data', 'Pessoa', 'Projeto', 'Work item', 'Título do work item', 'Atividade', 'Faturável',
                'Duração (HH:MM:SS)', 'Duração (segundos)', 'Origem', 'Estado da semana', 'Comentário',
            ]);

            foreach ($export['rows'] as $row) {
                CsvWriter::writeRow($out, [
                    $row['localDate'],
                    $row['memberName'],
                    $row['projectName'],
                    $row['workItemId'],
                    $row['workItemTitle'],
                    $row['activityTypeName'] ?? 'Não definido',
                    $row['billable'] ? 'Sim' : 'Não',
                    sprintf('%02d:%02d:%02d', intdiv($row['durationSeconds'], 3600), intdiv($row['durationSeconds'] % 3600, 60), $row['durationSeconds'] % 60),
                    $row['durationSeconds'],
                    $row['source'] === 'timer' ? 'Timer' : 'Manual',
                    self::WEEK_STATUS_LABELS[$row['weekStatus']] ?? $row['weekStatus'],
                    $row['note'],
                ]);
            }

            fclose($out);
        }, "horas_{$filters['from']}_a_{$filters['to']}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request, int $tenantId): array
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'projectId' => ['nullable', 'integer', 'min:1'],
            'memberId' => ['nullable', 'integer', 'min:1'],
            'workItemId' => ['nullable', 'integer', 'min:1'],
            // Inclui tipos desabilitados: lançamentos antigos ainda os usam.
            'activityTypeId' => ['nullable', 'integer', Rule::exists('activity_types', 'id')->where('tenant_id', $tenantId)],
            'billable' => ['nullable', Rule::in(['0', '1', 'true', 'false'])],
            'status' => ['nullable', Rule::in(array_keys(self::WEEK_STATUS_LABELS))],
        ]);

        if (CarbonImmutable::parse($data['from'])->diffInDays(CarbonImmutable::parse($data['to'])) > self::MAX_RANGE_DAYS) {
            throw ValidationException::withMessages(['to' => 'O período pode ter no máximo '.self::MAX_RANGE_DAYS.' dias.']);
        }

        $filters = ['from' => $data['from'], 'to' => $data['to']];

        foreach (['projectId', 'memberId', 'workItemId', 'activityTypeId'] as $key) {
            if (isset($data[$key])) {
                $filters[$key] = (int) $data[$key];
            }
        }

        if (isset($data['billable'])) {
            $filters['billable'] = in_array($data['billable'], ['1', 'true'], true);
        }

        if (isset($data['status'])) {
            $filters['status'] = $data['status'];
        }

        return $filters;
    }
}
