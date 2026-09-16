<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Technical;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Technical\TechnicalAccessoriesVisibility;

final class TechnicalAccessoriesVisibilityTest extends TestCase
{
    public function test_employee_role_allows(): void
    {
        self::assertTrue(TechnicalAccessoriesVisibility::allows(['EMPLOYEE'], []));
    }

    public function test_administrator_role_allows(): void
    {
        self::assertTrue(TechnicalAccessoriesVisibility::allows(['ADMINISTRATOR'], []));
    }

    public function test_sw_digit_markets_allow(): void
    {
        foreach (['SW0', 'SW1', 'SW2', 'SW3', 'SW4', 'SW5', 'sw9'] as $code) {
            self::assertTrue(
                TechnicalAccessoriesVisibility::allows(['CUSTOMER_USER'], [$code]),
                $code,
            );
        }
    }

    public function test_non_sw_and_empty_markets_deny_for_customer(): void
    {
        self::assertFalse(TechnicalAccessoriesVisibility::allows(['CUSTOMER_USER'], ['ENERGY']));
        self::assertFalse(TechnicalAccessoriesVisibility::allows(['CUSTOMER_USER'], ['SW10']));
        self::assertFalse(TechnicalAccessoriesVisibility::allows(['CUSTOMER_USER'], ['SW']));
        self::assertFalse(TechnicalAccessoriesVisibility::allows(['CUSTOMER_USER'], []));
    }

    public function test_multi_market_with_sw_allows(): void
    {
        self::assertTrue(TechnicalAccessoriesVisibility::allows(
            ['CUSTOMER_USER'],
            ['ENERGY', 'SW3', 'CONSULT1'],
        ));
    }
}
