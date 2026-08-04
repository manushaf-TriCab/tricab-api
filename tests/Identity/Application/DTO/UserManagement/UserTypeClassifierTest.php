<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Identity\Application\DTO\UserManagement;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Identity\Application\DTO\UserManagement\UserTypeClassifier;

final class UserTypeClassifierTest extends TestCase
{
    public function test_classifies_customer_roles_ahead_of_guest(): void
    {
        self::assertSame(
            UserTypeClassifier::CUSTOMER,
            UserTypeClassifier::fromRoleCodes(['GUEST', 'CUSTOMER_USER']),
        );
    }

    public function test_classifies_guest_and_internal(): void
    {
        self::assertSame(UserTypeClassifier::GUEST, UserTypeClassifier::fromRoleCodes(['GUEST']));
        self::assertSame(UserTypeClassifier::INTERNAL, UserTypeClassifier::fromRoleCodes(['EMPLOYEE']));
        self::assertSame(UserTypeClassifier::INTERNAL, UserTypeClassifier::fromRoleCodes([]));
    }
}
