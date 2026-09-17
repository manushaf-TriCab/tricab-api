<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\QuoteOrder\Domain;

use PHPUnit\Framework\TestCase;
use TriCab\Core\QuoteOrder\Domain\ValueObject\MoneyTotal;
use TriCab\Core\QuoteOrder\Domain\ValueObject\QuoteOrderType;
use TriCab\Core\QuoteOrder\Domain\ValueObject\QuoteStatus;

final class QuoteOrderValueObjectTest extends TestCase
{
    public function test_quote_status_maps_known_codes_only(): void
    {
        self::assertSame(['draft', '1705', 'Draft'], $this->statusTuple(QuoteStatus::fromCode(1705)));
        self::assertSame(['finalized', '1703', 'Finalized'], $this->statusTuple(QuoteStatus::fromCode('1703')));
        self::assertSame(['open', '1710', 'Open'], $this->statusTuple(QuoteStatus::fromCode('1710')));
        self::assertSame(['closed', '1711', 'Closed'], $this->statusTuple(QuoteStatus::fromCode(1711)));

        $unknown = QuoteStatus::fromCode('9999');
        self::assertSame('unknown', $unknown->status);
        self::assertSame('9999', $unknown->statusCode);
        self::assertSame('Unknown', $unknown->statusLabel);
    }

    public function test_tbc_total_is_null_pending_not_string_tbc(): void
    {
        $pending = MoneyTotal::fromLegacyNet('0.00');
        self::assertNull($pending->amount);
        self::assertSame(MoneyTotal::STATUS_PENDING, $pending->status);

        $zero = MoneyTotal::fromLegacyNet(0);
        self::assertNull($zero->amount);
        self::assertSame(MoneyTotal::STATUS_PENDING, $zero->status);

        $resolved = MoneyTotal::fromLegacyNet('504.19');
        self::assertSame('504.19', $resolved->amount);
        self::assertSame(MoneyTotal::STATUS_RESOLVED, $resolved->status);
    }

    public function test_quote_order_type_values(): void
    {
        self::assertTrue(QuoteOrderType::quote()->isQuote());
        self::assertTrue(QuoteOrderType::order()->isOrder());
        self::assertTrue(QuoteOrderType::all()->isAll());
        self::assertSame('quote', QuoteOrderType::fromString('QUOTE')->toString());
    }

    /**
     * @return list<string>
     */
    private function statusTuple(QuoteStatus $status): array
    {
        return [$status->status, $status->statusCode, $status->statusLabel];
    }
}
