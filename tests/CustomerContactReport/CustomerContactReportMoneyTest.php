<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\CustomerContactReport;

use PHPUnit\Framework\TestCase;
use TriCab\Core\CustomerContactReport\Domain\CustomerContactCreditEscalation;
use TriCab\Core\CustomerContactReport\Domain\CustomerContactReportMoney;
use TriCab\Core\CustomerContactReport\Domain\Exception\InvalidCustomerContactReportValue;

final class CustomerContactReportMoneyTest extends TestCase
{
    public function test_amounts_stay_decimal_and_the_threshold_is_strictly_greater_than_1000(): void
    {
        self::assertSame('0.00', CustomerContactReportMoney::fromDecimal('0', 'creditTotal')->toDecimal());
        self::assertSame('1000.00', CustomerContactReportMoney::fromDecimal('1000', 'creditTotal')->toDecimal());
        self::assertSame('1000.01', CustomerContactReportMoney::fromDecimal('1000.01', 'creditTotal')->toDecimal());
        self::assertSame('2500000.50', CustomerContactReportMoney::fromDecimal('2500000.50', 'creditTotal')->toDecimal());
        self::assertSame('9999999999999999.99', CustomerContactReportMoney::fromDecimal('9999999999999999.99', 'creditTotal')->toDecimal());

        self::assertFalse(CustomerContactCreditEscalation::climbsOneLevel($this->money('0')));
        self::assertFalse(CustomerContactCreditEscalation::climbsOneLevel($this->money('999.99')));
        self::assertFalse(CustomerContactCreditEscalation::climbsOneLevel($this->money('1000')));
        self::assertFalse(CustomerContactCreditEscalation::climbsOneLevel($this->money('1000.00')));
        self::assertTrue(CustomerContactCreditEscalation::climbsOneLevel($this->money('1000.01')));
        self::assertTrue(CustomerContactCreditEscalation::climbsOneLevel($this->money('2500000.50')));
    }

    public function test_negative_and_over_precise_amounts_are_rejected(): void
    {
        foreach (['-1', '-0.01', '1000.001', '1.234', '10000000000000000.00', 'abc'] as $raw) {
            $rejected = null;
            try {
                CustomerContactReportMoney::fromDecimal($raw, 'creditTotal');
            } catch (InvalidCustomerContactReportValue $exception) {
                $rejected = $exception;
            }
            self::assertInstanceOf(InvalidCustomerContactReportValue::class, $rejected, $raw . ' was accepted.');
        }
    }

    private function money(string $raw): CustomerContactReportMoney
    {
        return CustomerContactReportMoney::fromDecimal($raw, 'creditTotal');
    }
}
