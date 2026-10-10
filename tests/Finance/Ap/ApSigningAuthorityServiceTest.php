<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Finance\Ap\InMemoryApSigningLimitStore;
use TriCab\Core\Finance\Ap\Domain\ApSigningLimit;
use TriCab\Core\Finance\Ap\Service\ApSigningAuthorityService;

/** `GET /signing-authority`: a plain read of the limits (T6.3, question Q12). */
final class ApSigningAuthorityServiceTest extends TestCase
{
    public function test_it_lists_active_positions_with_an_address_by_name_and_keeps_a_null_limit_null(): void
    {
        $store = new InMemoryApSigningLimitStore();
        $store->add(new ApSigningLimit('2', 'CFO', 'CFO', 'Zed Zane', 'zed@example.test', 'Chief Financial Officer', 99999999999.0, 99999999999.0, 5000.0, null, 250.0, true));
        $store->add(new ApSigningLimit('1', 'Manager', 'MGR', 'Amy Adams', 'amy@example.test', 'Finance Manager', 1000.0, 500.0, 100.0, 0.0, null, true));
        $store->add(new ApSigningLimit('3', 'Manager', 'MGR', 'Vacant', '', 'Vacant Seat', 1.0, 1.0, 1.0, 1.0, 1.0, true));
        $store->add(new ApSigningLimit('4', 'Manager', 'MGR', 'Gone Gail', 'gail@example.test', 'Retired Seat', 1.0, 1.0, 1.0, 1.0, 1.0, false));

        $rows = (new ApSigningAuthorityService($store))->list();

        self::assertCount(2, $rows, 'a vacant position and an inactive one are left out');
        self::assertSame([
            'name' => 'Amy Adams',
            'full_name' => 'Amy Adams',
            'email' => 'amy@example.test',
            'position' => 'Finance Manager',
            'd365_position' => 'Finance Manager',
            'authority_role' => 'Manager',
            'limits' => ['po_inventory' => 1000.0, 'po_non_inventory' => 500.0, 'expense_claim' => 100.0, 'customer_order' => 0.0, 'credit_notes' => null],
        ], $rows[0]);
        self::assertSame('Zed Zane', $rows[1]['name']);
        $limits = $rows[1]['limits'];
        self::assertIsArray($limits);
        self::assertNull($limits['customer_order']);
        self::assertSame(99999999999.0, $limits['po_inventory'], 'unlimited is kept as the source wrote it');
    }
}
