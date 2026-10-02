<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Feriados nacionais de um ano, para o administrador não digitar um a um.
 * Estaduais e municipais (e pontos facultativos, como Carnaval e Corpus
 * Christi) ficam por conta de quem administra a organização.
 */
final class NationalHolidays
{
    /** @var array<string, string> mês-dia → nome */
    private const FIXED = [
        '01-01' => 'Confraternização Universal',
        '04-21' => 'Tiradentes',
        '05-01' => 'Dia do Trabalho',
        '09-07' => 'Independência do Brasil',
        '10-12' => 'Nossa Senhora Aparecida',
        '11-02' => 'Finados',
        '11-15' => 'Proclamação da República',
        '11-20' => 'Consciência Negra',
        '12-25' => 'Natal',
    ];

    /**
     * @return array<string, string> data 'Y-m-d' → nome
     */
    public static function forYear(int $year): array
    {
        $holidays = [];

        foreach (self::FIXED as $monthDay => $name) {
            $holidays["{$year}-{$monthDay}"] = $name;
        }

        $holidays[self::easter($year)->subDays(2)->toDateString()] = 'Sexta-feira Santa';

        ksort($holidays);

        return $holidays;
    }

    /** Domingo de Páscoa (algoritmo de Meeus/Jones/Butcher). */
    public static function easter(int $year): CarbonImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return CarbonImmutable::create($year, $month, $day, 0, 0, 0, 'UTC');
    }
}
