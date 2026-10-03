<?php

namespace App\Http\Controllers;

use App\Models\AdditionalHourReview;
use App\Services\AdditionalHoursClassificationService;
use App\Services\AuditService;
use App\Services\MonthlyClosingService;
use App\Support\CsvWriter;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Horas adicionais de semanas aprovadas, para o administrador decidir o
 * destino: hora extra, banco de horas ou a pagar (middleware `admin`).
 */
class AdditionalHoursController extends Controller
{
    private const MAX_RANGE_DAYS = 366;

    private const CATEGORY_LABELS = [
        'overtime' => 'Hora extra',
        'payable' => 'A pagar',
        'bank' => 'Banco de horas: crédito',
        'pending' => 'A classificar (semana aprovada)',
        'unapproved' => 'Semana ainda não aprovada',
    ];

    private const BANK_LABELS = [
        'creditedSeconds' => 'Banco de horas: crédito no mês',
        'timeOffSeconds' => 'Banco de horas: folga',
        'payoutSeconds' => 'Banco de horas: pago em folha',
        'adjustmentSeconds' => 'Banco de horas: ajuste',
        'expiredSeconds' => 'Banco de horas: vencido (pagar como hora extra)',
        'balanceSeconds' => 'Banco de horas: saldo no fim do mês',
    ];

    private const ALERT_LABELS = [
        'daily_extra' => 'Aviso: horas extras acima do limite diário',
        'weekly_hours' => 'Aviso: semana acima do limite',
        'rest' => 'Aviso: descanso entre jornadas abaixo do mínimo',
    ];

    private const REGIME_LABELS = ['clt' => 'CLT', 'pj' => 'PJ', 'none' => 'Não controla jornada'];

    public function __construct(
        private readonly AdditionalHoursClassificationService $classification,
        private readonly MonthlyClosingService $closing,
        private readonly AuditService $audit,
        private readonly TenantContext $tenantContext,
    ) {}

    /** Fechamento do mês (padrão: mês atual), para conferir antes de exportar. */
    public function closing(Request $request): JsonResponse
    {
        return response()->json($this->closing->month($this->tenantContext->tenant(), $this->closingMonth($request)));
    }

    /** Fechamento do mês em CSV para o DP: uma linha por pessoa, categoria e fator. */
    public function closingCsv(Request $request): StreamedResponse
    {
        $tenant = $this->tenantContext->tenant();
        $closing = $this->closing->month($tenant, $this->closingMonth($request));

        $this->audit->record($tenant, 'closing.exported', 'MonthlyClosing', null, $this->tenantContext->member(), null, [
            'month' => $closing['month'],
            'memberCount' => count($closing['members']),
        ]);

        return response()->streamDownload(function () use ($closing) {
            $out = fopen('php://output', 'w');
            CsvWriter::writeBom($out);
            CsvWriter::writeRow($out, [
                'Mês', 'Pessoa', 'Regime', 'Categoria', 'Fator', 'Horas (HH:MM)', 'Horas (decimal)',
                'Noturnas (HH:MM)', 'Ponderadas (HH:MM)', 'Ponderadas (decimal)', 'Ocorrências',
            ]);

            foreach ($closing['members'] as $member) {
                $person = [$closing['month'], $member['memberName'], self::REGIME_LABELS[$member['regime']] ?? $member['regime']];

                foreach ($member['lines'] as $line) {
                    CsvWriter::writeRow($out, [
                        ...$person,
                        self::CATEGORY_LABELS[$line['category']],
                        $line['factor'] === null ? '' : number_format($line['factor'], 2, ',', ''),
                        self::clock($line['seconds']),
                        self::decimal($line['seconds']),
                        self::clock($line['nightSeconds']),
                        self::clock($line['weightedSeconds']),
                        self::decimal($line['weightedSeconds']),
                        '',
                    ]);
                }

                foreach (self::BANK_LABELS as $field => $label) {
                    $seconds = $member['bank'][$field] ?? 0;
                    if ($member['bank'] !== null && ($seconds !== 0 || $field === 'balanceSeconds')) {
                        CsvWriter::writeRow($out, [...$person, $label, '', self::clock($seconds), self::decimal($seconds), '', '', '', '']);
                    }
                }

                if ($member['overtime']['withoutRequestSeconds'] > 0) {
                    $seconds = $member['overtime']['withoutRequestSeconds'];
                    CsvWriter::writeRow($out, [...$person, 'Hora adicional sem pedido aprovado', '', self::clock($seconds), self::decimal($seconds), '', '', '', '']);
                }
                if ($member['overtime']['refusedSeconds'] > 0) {
                    $seconds = $member['overtime']['refusedSeconds'];
                    CsvWriter::writeRow($out, [...$person, 'Hora a confirmar recusada (não conta)', '', self::clock($seconds), self::decimal($seconds), '', '', '', '']);
                }

                foreach (self::ALERT_LABELS as $type => $label) {
                    if ($member['alerts'][$type] > 0) {
                        CsvWriter::writeRow($out, [...$person, $label, '', '', '', '', '', '', $member['alerts'][$type]]);
                    }
                }
            }

            fclose($out);
        }, "fechamento_{$closing['month']}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function closingMonth(Request $request): string
    {
        $data = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);

        return $data['month'] ?? CarbonImmutable::instance(Date::now())
            ->setTimezone($this->tenantContext->tenant()->default_timezone ?: 'UTC')
            ->format('Y-m');
    }

    /** Segundos em "HH:MM", com sinal quando negativo. */
    private static function clock(int $seconds): string
    {
        $minutes = (int) round(abs($seconds) / 60);

        return ($seconds < 0 ? '−' : '').sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /** Horas decimais com vírgula (Excel pt-BR): 5400 → "1,50". */
    private static function decimal(int $seconds): string
    {
        return number_format($seconds / 3600, 2, ',', '');
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'memberId' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', Rule::in(['pending', 'classified', 'all'])],
        ]);

        $tenant = $this->tenantContext->tenant();
        $today = CarbonImmutable::instance(Date::now())->setTimezone($tenant->default_timezone ?: 'UTC');
        $to = $data['to'] ?? $today->toDateString();
        $from = $data['from'] ?? CarbonImmutable::parse($to)->subDays(90)->toDateString();

        if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > self::MAX_RANGE_DAYS) {
            throw ValidationException::withMessages(['from' => 'Escolha um período de até um ano.']);
        }

        return response()->json([
            'from' => $from,
            'to' => $to,
            'items' => $this->classification->list(
                $tenant,
                $from,
                $to,
                isset($data['memberId']) ? (int) $data['memberId'] : null,
                $data['status'] ?? 'pending',
            ),
        ]);
    }

    public function classify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'entryIds' => ['required', 'array', 'min:1', 'max:500'],
            'entryIds.*' => ['required', 'integer', 'min:1'],
            'classification' => ['required', Rule::in([...AdditionalHourReview::CLASSIFICATIONS, AdditionalHoursClassificationService::PENDING])],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $count = $this->classification->classify(
            $this->tenantContext->tenant(),
            $this->tenantContext->member(),
            array_map('intval', $data['entryIds']),
            $data['classification'],
            $data['note'] ?? null,
        );

        return response()->json(['updated' => $count]);
    }
}
