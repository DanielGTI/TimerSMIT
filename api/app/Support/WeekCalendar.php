<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Semana de segunda a domingo (padrão do MVP; configurável só para períodos
 * futuros, US5). Datas trafegam como texto 'Y-m-d' — já são datas locais,
 * sem fuso a converter.
 */
final class WeekCalendar
{
    public static function startOf(string $date): string
    {
        return CarbonImmutable::parse($date)->startOfWeek(CarbonInterface::MONDAY)->toDateString();
    }

    public static function endOf(string $weekStart): string
    {
        return CarbonImmutable::parse($weekStart)->addDays(6)->toDateString();
    }

    public static function isWeekStart(string $date): bool
    {
        return self::startOf($date) === $date;
    }

    /** @return list<string> os 7 dias da semana, segunda a domingo */
    public static function days(string $weekStart): array
    {
        $start = CarbonImmutable::parse($weekStart);

        return array_map(fn (int $offset) => $start->addDays($offset)->toDateString(), range(0, 6));
    }
}
