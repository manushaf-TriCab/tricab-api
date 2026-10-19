<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Mail;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Mail\Domain\MailActor;
use TriCab\Core\Mail\Domain\Service\MailAccessPolicy;
use TriCab\Core\Mail\Module;

final class MailAccessPolicyTest extends TestCase
{
    /** @param list<string> $permissions */
    private static function actor(array $permissions): MailActor
    {
        return new MailActor(7, 'Sam Sales', 'sam@tricab.com', $permissions);
    }

    public function test_access_is_the_access_permission_and_nothing_else(): void
    {
        self::assertTrue(MailAccessPolicy::canAccess(self::actor([Module::PERMISSION_ACCESS])));
        self::assertFalse(MailAccessPolicy::canAccess(self::actor([])));
        self::assertFalse(MailAccessPolicy::canAccess(self::actor([Module::PERMISSION_REVIEW, 'RFQ.ACCESS'])));
    }

    public function test_reviewing_needs_both_permissions(): void
    {
        self::assertTrue(MailAccessPolicy::canReview(self::actor([Module::PERMISSION_ACCESS, Module::PERMISSION_REVIEW])));
        self::assertFalse(MailAccessPolicy::canReview(self::actor([Module::PERMISSION_ACCESS])));
        // A reviewer who cannot open the inbox could not see what they review.
        self::assertFalse(MailAccessPolicy::canReview(self::actor([Module::PERMISSION_REVIEW])));
    }

    public function test_export_follows_review_and_no_other_permission(): void
    {
        self::assertTrue(MailAccessPolicy::canExport(self::actor([Module::PERMISSION_ACCESS, Module::PERMISSION_REVIEW])));
        self::assertFalse(MailAccessPolicy::canExport(self::actor([Module::PERMISSION_ACCESS, 'RFQ.ADMIN', 'FINANCE.ADMIN'])));
        self::assertFalse(MailAccessPolicy::canExport(self::actor([Module::PERMISSION_REVIEW])));
    }
}
