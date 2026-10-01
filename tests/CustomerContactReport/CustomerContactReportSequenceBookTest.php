<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\CustomerContactReport;

use PHPUnit\Framework\TestCase;
use TriCab\Core\CustomerContactReport\Domain\CustomerContactReportNumber;
use TriCab\Core\CustomerContactReport\Domain\CustomerContactReportSequenceBook;
use TriCab\Core\CustomerContactReport\Domain\CustomerContactReportSequenceLocked;

final class CustomerContactReportSequenceBookTest extends TestCase
{
    public function test_the_first_year_row_cannot_be_taken_twice(): void
    {
        $book = new CustomerContactReportSequenceBook();

        self::assertSame(1, $book->allocate(1, 2026));

        $this->expectException(CustomerContactReportSequenceLocked::class);
        $book->allocate(2, 2026);
    }

    public function test_the_waiting_transaction_continues_after_commit(): void
    {
        $book = new CustomerContactReportSequenceBook();
        self::assertSame(1, $book->allocate(1, 2026));
        $book->commit(1);

        self::assertSame(2, $book->allocate(2, 2026));
        $book->commit(2);

        self::assertSame(2, $book->committedValue(2026));
        self::assertSame(1, $book->committedYears());
    }

    public function test_a_rolled_back_first_year_row_is_not_consumed(): void
    {
        $book = new CustomerContactReportSequenceBook();
        self::assertSame(1, $book->allocate(1, 2026));
        $book->rollBack(1);

        self::assertSame(0, $book->committedValue(2026));
        self::assertSame(0, $book->committedYears());
        self::assertSame(1, $book->allocate(2, 2026));
    }

    public function test_different_years_do_not_block_each_other(): void
    {
        $book = new CustomerContactReportSequenceBook();
        self::assertSame(1, $book->allocate(1, 2026));
        self::assertSame(1, $book->allocate(2, 2027));
    }

    public function test_padding_keeps_at_least_three_digits_and_does_not_truncate_1000(): void
    {
        $january = new \DateTimeImmutable('2026-01-15');
        $september = new \DateTimeImmutable('2026-09-30');

        self::assertSame('2601-001M-SLS', CustomerContactReportNumber::format($january, 1, 'M', 'SLS'));
        self::assertSame('2609-248M-SLS', CustomerContactReportNumber::format($september, 248, 'M', 'SLS'));
        self::assertSame('2609-999M-SLS', CustomerContactReportNumber::format($september, 999, 'M', 'SLS'));
        self::assertSame('2609-1000M-SLS', CustomerContactReportNumber::format($september, 1000, 'M', 'SLS'));
    }
}
