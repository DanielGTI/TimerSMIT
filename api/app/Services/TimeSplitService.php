<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Particiona um intervalo UTC em fatias por dia local (US1, T016). A soma
 * das fatias é sempre igual a fim menos início, em segundos inteiros — não
 * há perda nem duplicação de segundos na fronteira da meia-noite local.
 */
class TimeSplitService
{
    /**
     * @return list<array{localDate: string, durationSeconds: int}>
     */
    public function split(DateTimeInterface $startedAtUtc, DateTimeInterface $endedAtUtc, string $timezone): array
    {
        $cursor = CarbonImmutable::instance($startedAtUtc)->utc();
        $end = CarbonImmutable::instance($endedAtUtc)->utc();

        if ($end->lessThanOrEqualTo($cursor)) {
            return [];
        }

        $slices = [];

        while ($cursor->lessThan($end)) {
            $cursorLocal = $cursor->setTimezone($timezone);
            $nextBoundaryUtc = $cursorLocal->startOfDay()->addDay()->utc();

            if ($nextBoundaryUtc->greaterThan($end)) {
                $nextBoundaryUtc = $end;
            }

            // Timestamps inteiros: diffInSeconds() devolve float (com
            // microssegundos) e a coluna duration_seconds é inteira.
            $durationSeconds = $nextBoundaryUtc->getTimestamp() - $cursor->getTimestamp();

            if ($durationSeconds > 0) {
                $slices[] = [
                    'localDate' => $cursorLocal->toDateString(),
                    'durationSeconds' => $durationSeconds,
                ];
            }

            $cursor = $nextBoundaryUtc;
        }

        return $slices;
    }
}
