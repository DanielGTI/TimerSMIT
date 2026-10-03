<?php

namespace App\Http\Controllers;

use App\Services\AuditService;
use App\Services\ProjectHoursReportService;
use App\Support\TenantContext;
use App\Support\XlsxWriter;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Horas do mês por projeto e pessoa (só administradores), na tela e em
 * Excel no formato enviado ao administrativo.
 */
class ProjectHoursController extends Controller
{
    private const MONTHS = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];

    public function __construct(
        private readonly ProjectHoursReportService $reports,
        private readonly AuditService $audit,
        private readonly TenantContext $tenantContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->report($request));
    }

    public function xlsx(Request $request): BinaryFileResponse
    {
        $report = $this->report($request);
        [$year, $month] = explode('-', $report['month']);
        $title = self::MONTHS[(int) $month - 1].' de '.$year;

        $sheet = new XlsxWriter;
        $sheet->text(1, 1, $title, XlsxWriter::TITLE)->height(1, 30);
        $sheet->text(2, 1, $report['note'], XlsxWriter::NOTE);

        $people = $report['people'];
        $last = count($people) + 2;

        $sheet->text(4, 1, 'Projetos', XlsxWriter::HEADER)->height(4, 44)->width(1, 28);
        foreach ($people as $index => $person) {
            $sheet->text(4, $index + 2, $person['name'], XlsxWriter::HEADER)->width($index + 2, 20);
        }
        $sheet->text(4, $last, 'TOTAL', XlsxWriter::HEADER)->width($last, 14);

        $row = 5;
        foreach ($report['projects'] as $project) {
            $sheet->text($row, 1, $project['name'], XlsxWriter::ROW_LABEL);
            foreach ($people as $index => $person) {
                $seconds = $project['seconds'][$person['memberId']] ?? 0;
                $seconds > 0 ? $sheet->duration($row, $index + 2, $seconds) : $sheet->blank($row, $index + 2);
            }
            $sheet->duration($row, $last, $project['totalSeconds']);
            $row++;
        }

        $sheet->text($row, 1, 'Horas Ociosas', XlsxWriter::HIGHLIGHT_LABEL);
        foreach ($people as $index => $person) {
            $sheet->duration($row, $index + 2, $person['idleSeconds'], XlsxWriter::HIGHLIGHT_DURATION);
        }
        $sheet->duration($row, $last, $report['totals']['idleSeconds'], XlsxWriter::HIGHLIGHT_DURATION);
        $row++;

        $sheet->text($row, 1, 'TOTAL', XlsxWriter::TOTAL_LABEL);
        foreach ($people as $index => $person) {
            $sheet->duration($row, $index + 2, $person['totalSeconds'], XlsxWriter::TOTAL_DURATION);
        }
        $sheet->duration($row, $last, $report['totals']['totalSeconds'], XlsxWriter::TOTAL_DURATION);

        $path = tempnam(sys_get_temp_dir(), 'horas-projeto-');
        $sheet->save($path, $title);

        $this->audit->record($this->tenantContext->tenant(), 'project_hours.exported', 'ProjectHoursReport', null, $this->tenantContext->member(), null, [
            'month' => $report['month'],
            'approvedOnly' => $report['approvedOnly'],
            'dailyHours' => $report['dailyHours'],
        ]);

        return response()
            ->download($path, "horas_por_projeto_{$report['month']}.xlsx", [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend();
    }

    /**
     * @return array<string, mixed>
     */
    private function report(Request $request): array
    {
        $data = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'dailyHours' => ['nullable', 'numeric', 'between:1,24'],
            'approvedOnly' => ['nullable', 'boolean'],
        ]);

        $tenant = $this->tenantContext->tenant();
        $month = $data['month'] ?? CarbonImmutable::instance(Date::now())->setTimezone($tenant->default_timezone ?: 'UTC')->format('Y-m');

        return $this->reports->month(
            $tenant,
            $month,
            (float) ($data['dailyHours'] ?? 8),
            filter_var($data['approvedOnly'] ?? false, FILTER_VALIDATE_BOOLEAN),
        );
    }
}
