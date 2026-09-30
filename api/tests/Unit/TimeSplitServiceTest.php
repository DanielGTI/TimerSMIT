<?php

namespace Tests\Unit;

use App\Services\TimeSplitService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * duration_seconds é coluna inteira: o serviço nunca pode devolver fração
 * (o SQLite dos testes de feature aceita float em coluna inteira, o
 * PostgreSQL de produção recusa — por isso o teste é direto no serviço).
 */
class TimeSplitServiceTest extends TestCase
{
    public function test_durations_are_whole_seconds_even_with_microseconds(): void
    {
        $slices = (new TimeSplitService)->split(
            CarbonImmutable::parse('2026-09-30 13:00:00.000000', 'UTC'),
            CarbonImmutable::parse('2026-09-30 13:05:00.641548', 'UTC'),
            'America/Sao_Paulo',
        );

        $this->assertCount(1, $slices);
        $this->assertIsInt($slices[0]['durationSeconds']);
        $this->assertSame(300, $slices[0]['durationSeconds']);
        $this->assertSame('2026-09-30', $slices[0]['localDate']);
    }

    public function test_slices_around_local_midnight_add_up_to_the_total(): void
    {
        // 23:00 -> 01:00 em America/Sao_Paulo (UTC-3), com microssegundos.
        $slices = (new TimeSplitService)->split(
            CarbonImmutable::parse('2026-10-01 02:00:00', 'UTC'),
            CarbonImmutable::parse('2026-10-01 04:00:00.900000', 'UTC'),
            'America/Sao_Paulo',
        );

        $this->assertSame(
            [
                ['localDate' => '2026-09-30', 'durationSeconds' => 3600],
                ['localDate' => '2026-10-01', 'durationSeconds' => 3600],
            ],
            $slices,
        );
    }

    public function test_multi_day_interval_produces_one_slice_per_local_day(): void
    {
        $slices = (new TimeSplitService)->split(
            CarbonImmutable::parse('2026-10-01 12:00:00', 'UTC'),
            CarbonImmutable::parse('2026-10-03 12:00:00', 'UTC'),
            'UTC',
        );

        $this->assertSame([43200, 86400, 43200], array_column($slices, 'durationSeconds'));
        $this->assertSame(172800, array_sum(array_column($slices, 'durationSeconds')));
    }

    public function test_empty_or_inverted_interval_yields_no_slices(): void
    {
        $at = CarbonImmutable::parse('2026-10-01 12:00:00', 'UTC');

        $this->assertSame([], (new TimeSplitService)->split($at, $at, 'UTC'));
        $this->assertSame([], (new TimeSplitService)->split($at, $at->subMinute(), 'UTC'));
    }
}
