<?php

namespace Tests\Unit;

use App\Support\HourBankLedger;
use PHPUnit\Framework\TestCase;

class HourBankLedgerTest extends TestCase
{
    private const H = 3600;

    private function credit(string $key, string $date, float $hours, string $expiresOn): array
    {
        return ['key' => $key, 'date' => $date, 'seconds' => (int) ($hours * self::H), 'expiresOn' => $expiresOn];
    }

    private function debit(string $key, string $date, float $hours): array
    {
        return ['key' => $key, 'date' => $date, 'seconds' => (int) ($hours * self::H)];
    }

    public function test_credits_and_debits_give_a_running_balance(): void
    {
        $ledger = HourBankLedger::compute(
            [$this->credit('a', '2026-03-07', 15, '2026-09-07'), $this->credit('b', '2026-04-04', 6, '2026-10-04')],
            [$this->debit('f', '2026-05-15', 8)],
            '2026-06-01',
        );

        $this->assertSame(13 * self::H, $ledger['balanceSeconds']);
        $this->assertSame([15, 21, 13], array_map(fn ($e) => $e['balanceSeconds'] / self::H, $ledger['events']));
        $this->assertSame(0, $ledger['shortfallSeconds']);
        // A folga consome primeiro o lote que vence antes.
        $this->assertSame(7 * self::H, $ledger['events'][0]['remainingSeconds']);
        $this->assertSame(6 * self::H, $ledger['events'][1]['remainingSeconds']);
    }

    public function test_what_is_left_on_the_expiry_date_expires_and_leaves_the_balance(): void
    {
        $ledger = HourBankLedger::compute(
            [$this->credit('a', '2026-03-07', 15, '2026-09-07'), $this->credit('b', '2026-08-01', 4, '2027-02-01')],
            [$this->debit('f', '2026-05-15', 5)],
            '2026-09-10',
        );

        $this->assertSame(10 * self::H, $ledger['expiredSeconds']);
        $this->assertSame(4 * self::H, $ledger['balanceSeconds']);

        $types = array_column($ledger['events'], 'type');
        $this->assertSame(['credit', 'debit', 'credit', 'expiry'], $types);
        $this->assertSame('2026-09-07', $ledger['events'][3]['date']);
        $this->assertSame(-10 * self::H, $ledger['events'][3]['seconds']);
        $this->assertTrue($ledger['events'][0]['expired']);
    }

    public function test_nothing_expires_before_today(): void
    {
        $ledger = HourBankLedger::compute([$this->credit('a', '2026-03-07', 15, '2026-09-07')], [], '2026-09-06');

        $this->assertSame(0, $ledger['expiredSeconds']);
        $this->assertSame(15 * self::H, $ledger['balanceSeconds']);
        $this->assertSame(['seconds' => 15 * self::H, 'date' => '2026-09-07'], $ledger['expiringSoon']);
    }

    public function test_a_debit_without_hours_to_cover_it_is_a_shortfall(): void
    {
        $ledger = HourBankLedger::compute([$this->credit('a', '2026-03-07', 4, '2026-09-07')], [$this->debit('f', '2026-04-01', 6)], '2026-04-02');

        $this->assertSame(2 * self::H, $ledger['shortfallSeconds']);
        $this->assertSame([['date' => '2026-04-01', 'seconds' => 2 * self::H]], $ledger['shortfalls']);
        $this->assertSame(-2 * self::H, $ledger['balanceSeconds']);
    }

    public function test_a_debit_cannot_use_hours_worked_after_it_or_already_expired(): void
    {
        $before = HourBankLedger::compute([$this->credit('a', '2026-05-01', 8, '2026-11-01')], [$this->debit('f', '2026-04-30', 8)], '2026-05-02');
        $this->assertSame(8 * self::H, $before['shortfallSeconds']);

        $expired = HourBankLedger::compute([$this->credit('a', '2026-01-10', 8, '2026-07-10')], [$this->debit('f', '2026-07-10', 8)], '2026-07-11');
        $this->assertSame(8 * self::H, $expired['shortfallSeconds']);
        $this->assertSame(8 * self::H, $expired['expiredSeconds']);
    }

    public function test_a_scheduled_day_off_cannot_use_hours_that_expire_before_it(): void
    {
        $ledger = HourBankLedger::compute(
            [$this->credit('a', '2026-03-07', 8, '2026-09-07'), $this->credit('b', '2026-06-01', 8, '2026-12-01')],
            [$this->debit('f', '2026-09-15', 8)],
            '2026-09-01',
        );

        // O lote "a" vence antes da folga: ela usa o "b", e o "a" segue no saldo até vencer.
        $this->assertSame(0, $ledger['shortfallSeconds']);
        $this->assertSame(8 * self::H, $ledger['events'][0]['remainingSeconds']);
        $this->assertSame(0, $ledger['events'][1]['remainingSeconds']);
        $this->assertSame(8 * self::H, $ledger['balanceSeconds']);
    }
}
