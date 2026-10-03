<?php

namespace App\Services;

use App\Models\Member;
use App\Models\OvertimeRule;
use App\Models\TimeEntry;
use App\Support\AdditionalHoursResult;
use Carbon\CarbonImmutable;

/**
 * Separa, em cada lançamento, o que é expediente do que é hora adicional.
 *
 *  - Sábado, domingo e feriado: o lançamento inteiro é adicional (só pela data).
 *  - Dia útil: o trecho fora do expediente — exige horário (De/Até ou timer);
 *    um lançamento antigo sem horário não gera hora adicional em dia útil.
 *  - Do trecho adicional, o que cai na janela noturna recebe o adicional noturno
 *    e, opcionalmente, a hora reduzida de 52min30s (CLT art. 73).
 *
 * Função pura: não acessa o banco. Quem chama informa a regra, se o dia é
 * feriado e o regime da pessoa.
 */
class AdditionalHoursCalculator
{
    private const DAY = 86400;

    /** 52min30s: uma hora noturna vale 3150s, então 3600s de relógio valem 3600/3150 horas noturnas. */
    private const REDUCED_NIGHT_HOUR_SECONDS = 3150;

    public function calculate(TimeEntry $entry, ?OvertimeRule $rule, bool $isHoliday, string $regime): AdditionalHoursResult
    {
        $dayType = $this->dayType($entry->local_date, $isHoliday);

        if ($rule === null || ! $rule->enabled || $regime === Member::REGIME_NONE || $entry->duration_seconds <= 0) {
            return AdditionalHoursResult::none($dayType);
        }

        $interval = $this->localInterval($entry);

        if ($dayType !== AdditionalHoursResult::WEEKDAY) {
            // Sem horário não há como separar o período noturno: tudo conta como diurno.
            $additional = $interval === null ? [] : [$interval];
            $total = $entry->duration_seconds;
        } else {
            if ($interval === null) {
                return AdditionalHoursResult::none($dayType);
            }

            $additional = $this->outsideWorkday($interval, $this->seconds($rule->workday_start), $this->seconds($rule->workday_end));
            $total = array_sum(array_map(fn (array $part) => $part[1] - $part[0], $additional));
        }

        if ($total <= 0) {
            return AdditionalHoursResult::none($dayType);
        }

        $night = $this->nightOverlap($additional, $this->seconds($rule->night_start), $this->seconds($rule->night_end));
        $factor = $this->factor($rule, $dayType);

        $nightFactor = $factor * (1 + $rule->night_percent / 100);
        if ($rule->night_reduced_hour) {
            $nightFactor *= 3600 / self::REDUCED_NIGHT_HOUR_SECONDS;
        }

        $weighted = (int) round(($total - $night) * $factor + $night * $nightFactor);

        return new AdditionalHoursResult($total, $night, $weighted, $dayType);
    }

    /**
     * Trechos adicionais do lançamento, em segundos desde 00:00 local. Em
     * sábado, domingo e feriado é o lançamento inteiro; sem horário nesses
     * dias, `parts` é nulo (só a duração é conhecida). Mesmas regras de
     * `calculate`, sem os fatores.
     *
     * @return array{seconds: int, parts: list<array{0: int, 1: int}>|null}
     */
    public function additionalParts(TimeEntry $entry, ?OvertimeRule $rule, bool $isHoliday, string $regime): array
    {
        if ($rule === null || ! $rule->enabled || $regime === Member::REGIME_NONE || $entry->duration_seconds <= 0) {
            return ['seconds' => 0, 'parts' => []];
        }

        $interval = $this->localInterval($entry);

        if ($this->dayType($entry->local_date, $isHoliday) !== AdditionalHoursResult::WEEKDAY) {
            return $interval === null
                ? ['seconds' => (int) $entry->duration_seconds, 'parts' => null]
                : ['seconds' => $interval[1] - $interval[0], 'parts' => [$interval]];
        }

        if ($interval === null) {
            return ['seconds' => 0, 'parts' => []];
        }

        $parts = $this->outsideWorkday($interval, $this->seconds($rule->workday_start), $this->seconds($rule->workday_end));

        return ['seconds' => array_sum(array_map(fn (array $part) => $part[1] - $part[0], $parts)), 'parts' => $parts];
    }

    public function dayType(string $localDate, bool $isHoliday): string
    {
        if ($isHoliday) {
            return AdditionalHoursResult::HOLIDAY;
        }

        return match (CarbonImmutable::parse(substr($localDate, 0, 10), 'UTC')->dayOfWeek) {
            0 => AdditionalHoursResult::SUNDAY,
            6 => AdditionalHoursResult::SATURDAY,
            default => AdditionalHoursResult::WEEKDAY,
        };
    }

    private function factor(OvertimeRule $rule, string $dayType): float
    {
        return match ($dayType) {
            AdditionalHoursResult::HOLIDAY => $rule->factor_holiday,
            AdditionalHoursResult::SUNDAY => $rule->factor_sunday,
            AdditionalHoursResult::SATURDAY => $rule->factor_saturday,
            default => $rule->factor_weekday,
        };
    }

    /**
     * Intervalo do lançamento em segundos desde 00:00 da hora local, ou nulo se
     * não houver horário. Um lançamento é sempre de uma data só.
     *
     * @return array{0: int, 1: int}|null
     */
    private function localInterval(TimeEntry $entry): ?array
    {
        if ($entry->started_at_utc === null || $entry->ended_at_utc === null) {
            return null;
        }

        $start = $entry->started_at_utc->copy()->setTimezone($entry->timezone ?: 'UTC');
        $from = $start->hour * 3600 + $start->minute * 60 + $start->second;
        $length = $entry->ended_at_utc->getTimestamp() - $entry->started_at_utc->getTimestamp();

        return [$from, min(self::DAY, $from + max(0, $length))];
    }

    /**
     * @param  array{0: int, 1: int}  $interval
     * @return list<array{0: int, 1: int}>
     */
    private function outsideWorkday(array $interval, int $workStart, int $workEnd): array
    {
        [$from, $to] = $interval;
        $parts = [];

        if ($from < $workStart) {
            $parts[] = [$from, min($to, $workStart)];
        }

        if ($to > $workEnd) {
            $parts[] = [max($from, $workEnd), $to];
        }

        return array_values(array_filter($parts, fn (array $part) => $part[1] > $part[0]));
    }

    /**
     * Segundos dos trechos que caem na janela noturna (que pode atravessar a meia-noite).
     *
     * @param  list<array{0: int, 1: int}>  $parts
     */
    private function nightOverlap(array $parts, int $nightStart, int $nightEnd): int
    {
        $windows = match (true) {
            $nightStart > $nightEnd => [[$nightStart, self::DAY], [0, $nightEnd]],
            $nightStart < $nightEnd => [[$nightStart, $nightEnd]],
            default => [],
        };

        $seconds = 0;
        foreach ($parts as [$from, $to]) {
            foreach ($windows as [$windowStart, $windowEnd]) {
                $seconds += max(0, min($to, $windowEnd) - max($from, $windowStart));
            }
        }

        return $seconds;
    }

    /** 'HH:MM' → segundos desde 00:00. */
    private function seconds(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return $hours * 3600 + $minutes * 60;
    }
}
