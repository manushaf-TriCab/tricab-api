<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\LegacyMigration;

use PHPUnit\Framework\TestCase;
use TriCab\Core\LegacyMigration\Live\UserIdentityClassifier;
use TriCab\Core\LegacyMigration\Support\UserClassification;

final class UserIdentityClassifierTest extends TestCase
{
    public function test_staff_customer_aliflex_and_ambiguous(): void
    {
        $c = new UserIdentityClassifier();
        $staff = $c->classify('thilan', 'thilan@tricab.com', true, [], ['staff'], false, 0, false, false);
        self::assertSame(UserClassification::STAFF_MICROSOFT, $staff['primary']);

        $staff = $c->classify('vendor', 'vendor@gmail.com', true, [], ['staff'], false, 0, false, false);
        self::assertSame(UserClassification::STAFF_OTHER, $staff['primary']);

        $customer = $c->classify('buyer@example.com', 'buyer@example.com', false, [
            ['matchType' => 'EXPLICIT_WP_ID'],
        ], ['customer'], true, 0, false, false);
        self::assertSame(UserClassification::CUSTOMER, $customer['primary']);
        self::assertContains(UserClassification::ALI_FLEX_USER, $customer['tags']);
        self::assertContains(UserClassification::CUSTOMER_CONTACT, $customer['tags']);

        $legacy = $c->classify('orphan', 'orphan@example.com', false, [], [], false, 0, false, false);
        self::assertSame(UserClassification::STAFF_OTHER, $legacy['primary']);

        $ambiguous = $c->classify('a@b.com', 'a@b.com', false, [
            ['matchType' => 'UNIQUE_EMAIL'],
            ['matchType' => 'EXPLICIT_WP_ID'],
        ], ['customer'], false, 0, true, true);
        self::assertContains(UserClassification::AMBIGUOUS, $ambiguous['tags']);
        self::assertContains(UserClassification::DUPLICATE, $ambiguous['tags']);
    }
}
