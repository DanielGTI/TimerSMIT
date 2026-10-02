<?php

namespace Tests\Unit;

use App\Models\Member;
use App\Models\OvertimeRule;
use App\Models\TimeEntry;
use App\Services\AdditionalHoursCalculator;
use App\Support\AdditionalHoursResult;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * Regra de referência: expediente 09:00–18:00, fatores 1,5 (dia útil),
 * 1,5 (sábado), 2 (domingo e feriado), noturno 22h–5h com +20% e hora reduzida.
 * 2026-09-29 é terça; 2026-10-03, sábado; 2026-10-04, domingo.
 */
class AdditionalHoursCalculatorTest extends TestCase
{
    private const TZ = 'America/Sao_Paulo';

    private AdditionalHoursCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new AdditionalHoursCalculator;
    }

    private function rule(array $overrides = []): OvertimeRule
    {
        return new OvertimeRule($overrides + [
            'enabled' => true,
            'workday_start' => '09:00',
            'workday_end' => '18:00',
            'factor_weekday' => 1.5,
            'factor_saturday' => 1.5,
            'factor_sunday' => 2.0,
            'factor_holiday' => 2.0,
            'night_start' => '22:00',
            'night_end' => '05:00',
            'night_percent' => 20,
            'night_reduced_hour' => true,
            'require_time_of_day' => true,
            'effective_from' => '2026-01-01 00:00:00',
        ]);
    }

    private function entry(string $date, ?string $start, int $minutes): TimeEntry
    {
        $entry = new TimeEntry([
            'local_date' => $date,
            'timezone' => self::TZ,
            'duration_seconds' => $minutes * 60,
        ]);

        if ($start !== null) {
            $from = CarbonImmutable::parse("{$date} {$start}", self::TZ);
            $entry->started_at_utc = $from->utc();
            $entry->ended_at_utc = $from->addMinutes($minutes)->utc();
        }

        return $entry;
    }

    private function calc(TimeEntry $entry, ?OvertimeRule $rule = null, bool $holiday = false, string $regime = Member::REGIME_CLT): AdditionalHoursResult
    {
        return $this->calculator->calculate($entry, $rule ?? $this->rule(), $holiday, $regime);
    }

    public function test_weekday_counts_only_what_falls_after_the_workday(): void
    {
        $result = $this->calc($this->entry('2026-09-29', '17:00', 180)); // 17h–20h

        $this->assertSame(7200, $result->additionalSeconds); // 18h–20h
        $this->assertSame(10800, $result->weightedSeconds); // × 1,5
        $this->assertSame(0, $result->nightSeconds);
        $this->assertSame(AdditionalHoursResult::WEEKDAY, $result->dayType);
    }

    public function test_weekday_counts_what_falls_before_the_workday_too(): void
    {
        $this->assertSame(7200, $this->calc($this->entry('2026-09-29', '07:00', 180))->additionalSeconds); // 07h–09h
    }

    public function test_weekday_inside_the_workday_is_not_additional(): void
    {
        $result = $this->calc($this->entry('2026-09-29', '10:00', 120));

        $this->assertFalse($result->isAdditional());
        $this->assertSame(0, $result->weightedSeconds);
    }

    public function test_weekday_without_time_of_day_is_not_additional(): void
    {
        $this->assertFalse($this->calc($this->entry('2026-09-29', null, 600))->isAdditional());
    }

    public function test_saturday_counts_the_whole_entry_even_without_time(): void
    {
        // O exemplo da SMIT: 10 horas num sábado com fator 1,5 viram 15 horas.
        $result = $this->calc($this->entry('2026-10-03', null, 600));

        $this->assertSame(36000, $result->additionalSeconds);
        $this->assertSame(54000, $result->weightedSeconds);
        $this->assertSame(AdditionalHoursResult::SATURDAY, $result->dayType);
    }

    public function test_sunday_and_holidays_use_their_own_factor(): void
    {
        $sunday = $this->calc($this->entry('2026-10-04', '10:00', 240));
        $this->assertSame(AdditionalHoursResult::SUNDAY, $sunday->dayType);
        $this->assertSame(28800, $sunday->weightedSeconds); // 4h × 2

        // Feriado em dia útil: mesmo dentro do expediente, o lançamento inteiro conta.
        $holiday = $this->calc($this->entry('2026-10-12', '10:00', 120), holiday: true);
        $this->assertSame(AdditionalHoursResult::HOLIDAY, $holiday->dayType);
        $this->assertSame(7200, $holiday->additionalSeconds);
        $this->assertSame(14400, $holiday->weightedSeconds);
    }

    public function test_night_part_gets_the_night_premium_and_the_reduced_hour(): void
    {
        $result = $this->calc($this->entry('2026-09-29', '21:00', 120)); // 21h–23h

        $this->assertSame(7200, $result->additionalSeconds);
        $this->assertSame(3600, $result->nightSeconds); // 22h–23h
        // 1h diurna × 1,5 + 1h noturna × 1,5 × 1,2 × (60 / 52,5)
        $this->assertSame((int) round(3600 * 1.5 + 3600 * 1.5 * 1.2 * 3600 / 3150), $result->weightedSeconds);
    }

    public function test_night_window_also_covers_the_early_morning_and_reduced_hour_can_be_turned_off(): void
    {
        $result = $this->calc($this->entry('2026-09-29', '04:00', 120), $this->rule(['night_reduced_hour' => false])); // 04h–06h

        $this->assertSame(7200, $result->additionalSeconds);
        $this->assertSame(3600, $result->nightSeconds); // 04h–05h
        $this->assertSame((int) round(3600 * 1.5 + 3600 * 1.5 * 1.2), $result->weightedSeconds);
    }

    public function test_an_entry_ending_at_midnight_counts_up_to_the_end_of_the_day(): void
    {
        $result = $this->calc($this->entry('2026-09-29', '23:00', 60), $this->rule(['night_reduced_hour' => false, 'night_percent' => 0]));

        $this->assertSame(3600, $result->additionalSeconds);
        $this->assertSame(3600, $result->nightSeconds);
        $this->assertSame(5400, $result->weightedSeconds);
    }

    public function test_nothing_counts_when_the_rule_is_off_missing_or_the_person_does_not_track_hours(): void
    {
        $saturday = $this->entry('2026-10-03', '10:00', 120);

        $this->assertFalse($this->calc($saturday, $this->rule(['enabled' => false]))->isAdditional());
        $this->assertFalse($this->calculator->calculate($saturday, null, false, Member::REGIME_CLT)->isAdditional());
        $this->assertFalse($this->calc($saturday, regime: Member::REGIME_NONE)->isAdditional());
        // PJ gera hora adicional normalmente (o destino é que fica restrito a "a pagar").
        $this->assertTrue($this->calc($saturday, regime: Member::REGIME_PJ)->isAdditional());
    }
}
