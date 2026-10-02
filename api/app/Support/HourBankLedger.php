<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Extrato do banco de horas, calculado do zero a cada leitura (nada aqui é
 * gravado). Regras:
 *
 * - Cada crédito é um lote com data e vencimento. Vale enquanto a data do uso
 *   for anterior ao vencimento.
 * - Cada débito consome primeiro o lote que vence antes (e, no empate, o mais
 *   antigo). O que não tiver lote para cobrir fica "sem cobertura".
 * - O que sobra num lote na data do vencimento vence: sai do saldo e vira hora
 *   extra a pagar. Só vence o que já venceu até hoje; débitos futuros (folga
 *   agendada) não usam lote que vence antes deles.
 *
 * Datas no formato Y-m-d, comparadas como texto.
 */
final class HourBankLedger
{
    /** Janela do aviso "vence em breve". */
    public const SOON_DAYS = 30;

    /**
     * @param  list<array{key: string, date: string, seconds: int, expiresOn: string, meta?: array<string, mixed>}>  $credits
     * @param  list<array{key: string, date: string, seconds: int, meta?: array<string, mixed>}>  $debits  segundos positivos
     * @return array{
     *     events: list<array<string, mixed>>,
     *     balanceSeconds: int,
     *     expiredSeconds: int,
     *     shortfallSeconds: int,
     *     shortfalls: list<array{date: string, seconds: int}>,
     *     expiringSoon: array{seconds: int, date: ?string},
     * }
     */
    public static function compute(array $credits, array $debits, string $today): array
    {
        $timeline = [];
        foreach ($credits as $credit) {
            $timeline[] = ['type' => 'credit'] + $credit;
        }
        foreach ($debits as $debit) {
            $timeline[] = ['type' => 'debit'] + $debit;
        }

        // No mesmo dia, o crédito entra antes do débito.
        usort($timeline, fn (array $a, array $b) => [$a['date'], $a['type'] === 'credit' ? 0 : 1, $a['key']]
            <=> [$b['date'], $b['type'] === 'credit' ? 0 : 1, $b['key']]);

        /** @var array<string, array{date: string, expiresOn: string, remaining: int, expired: bool, event: int}> $lots */
        $lots = [];
        $events = [];
        $balance = 0;
        $expiredTotal = 0;
        $shortfalls = [];

        $expireUntil = function (string $until) use (&$lots, &$events, &$balance, &$expiredTotal, $today): void {
            $limit = min($until, $today);
            $due = array_filter($lots, fn (array $lot) => ! $lot['expired'] && $lot['remaining'] > 0 && $lot['expiresOn'] <= $limit);
            uasort($due, fn (array $a, array $b) => [$a['expiresOn'], $a['date']] <=> [$b['expiresOn'], $b['date']]);

            foreach (array_keys($due) as $key) {
                $remaining = $lots[$key]['remaining'];
                $lots[$key]['expired'] = true;
                $balance -= $remaining;
                $expiredTotal += $remaining;
                $events[] = [
                    'type' => 'expiry',
                    'key' => 'expiry:'.$key,
                    'date' => $lots[$key]['expiresOn'],
                    'seconds' => -$remaining,
                    'balanceSeconds' => $balance,
                    'creditKey' => $key,
                    'creditDate' => $lots[$key]['date'],
                    'meta' => $events[$lots[$key]['event']]['meta'],
                ];
            }
        };

        foreach ($timeline as $item) {
            $expireUntil($item['date']);

            if ($item['type'] === 'credit') {
                $balance += $item['seconds'];
                $lots[$item['key']] = [
                    'date' => $item['date'],
                    'expiresOn' => $item['expiresOn'],
                    'remaining' => $item['seconds'],
                    'expired' => false,
                    'event' => count($events),
                ];
                $events[] = [
                    'type' => 'credit',
                    'key' => $item['key'],
                    'date' => $item['date'],
                    'seconds' => $item['seconds'],
                    'balanceSeconds' => $balance,
                    'expiresOn' => $item['expiresOn'],
                    'meta' => $item['meta'] ?? [],
                ];

                continue;
            }

            $needed = $item['seconds'];
            $usable = array_filter($lots, fn (array $lot) => ! $lot['expired'] && $lot['remaining'] > 0 && $lot['expiresOn'] > $item['date']);
            uasort($usable, fn (array $a, array $b) => [$a['expiresOn'], $a['date']] <=> [$b['expiresOn'], $b['date']]);

            foreach (array_keys($usable) as $key) {
                if ($needed === 0) {
                    break;
                }
                $taken = min($needed, $lots[$key]['remaining']);
                $lots[$key]['remaining'] -= $taken;
                $needed -= $taken;
            }

            if ($needed > 0) {
                $shortfalls[] = ['date' => $item['date'], 'seconds' => $needed];
            }

            $balance -= $item['seconds'];
            $events[] = [
                'type' => 'debit',
                'key' => $item['key'],
                'date' => $item['date'],
                'seconds' => -$item['seconds'],
                'balanceSeconds' => $balance,
                'uncoveredSeconds' => $needed,
                'meta' => $item['meta'] ?? [],
            ];
        }

        $expireUntil($today);

        $soonLimit = CarbonImmutable::parse($today)->addDays(self::SOON_DAYS)->toDateString();
        $soonSeconds = 0;
        $soonDate = null;

        foreach ($lots as $lot) {
            $events[$lot['event']]['remainingSeconds'] = $lot['expired'] ? 0 : $lot['remaining'];
            $events[$lot['event']]['expired'] = $lot['expired'];

            if (! $lot['expired'] && $lot['remaining'] > 0 && $lot['expiresOn'] > $today && $lot['expiresOn'] <= $soonLimit) {
                $soonSeconds += $lot['remaining'];
                $soonDate = $soonDate === null ? $lot['expiresOn'] : min($soonDate, $lot['expiresOn']);
            }
        }

        return [
            'events' => $events,
            'balanceSeconds' => $balance,
            'expiredSeconds' => $expiredTotal,
            'shortfallSeconds' => array_sum(array_column($shortfalls, 'seconds')),
            'shortfalls' => $shortfalls,
            'expiringSoon' => ['seconds' => $soonSeconds, 'date' => $soonDate],
        ];
    }
}
