<?php

namespace App\Http\Controllers;

use App\Models\AdditionalHourReview;
use App\Services\AdditionalHoursClassificationService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Horas adicionais de semanas aprovadas, para o administrador decidir o
 * destino: hora extra, banco de horas ou a pagar (middleware `admin`).
 */
class AdditionalHoursController extends Controller
{
    private const MAX_RANGE_DAYS = 366;

    public function __construct(
        private readonly AdditionalHoursClassificationService $classification,
        private readonly TenantContext $tenantContext,
    ) {}

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
