<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Access;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Finance\Access\InMemoryFinanceAccessScopeReader;
use TriCab\Core\Finance\Shared\Access\FinanceAccessScope;

/** The portal's rule for a scope, as the read service reports it: no row means unrestricted. */
final class FinanceAccessScopeTest extends TestCase
{
    public function test_a_person_with_no_row_is_unrestricted(): void
    {
        $scope = (new InMemoryFinanceAccessScopeReader())->forUser(7, 'CREDIT');

        self::assertTrue($scope->isUnrestricted());
        self::assertTrue($scope->allowsBranch('Melbourne'));
        self::assertTrue($scope->allowsMailbox('anything@tricab.com'));
        self::assertFalse($scope->restrictsBranches());
        self::assertFalse($scope->restrictsMailboxes());
    }

    public function test_a_person_with_branch_rows_sees_exactly_those_branches_whatever_the_case(): void
    {
        $reader = new InMemoryFinanceAccessScopeReader();
        $reader->grant(7, 'CREDIT', 'branch', ' Melbourne ');
        $reader->grant(7, 'CREDIT', 'branch', 'Sydney');
        $scope = $reader->forUser(7, 'CREDIT');

        self::assertFalse($scope->isUnrestricted());
        self::assertSame(['Melbourne', 'Sydney'], $scope->branches);
        self::assertTrue($scope->allowsBranch('melbourne'));
        self::assertTrue($scope->allowsBranch('SYDNEY'));
        self::assertFalse($scope->allowsBranch('Perth'));
        self::assertFalse($scope->allowsBranch(''));
    }

    public function test_a_mailbox_scope_is_compared_in_lower_case(): void
    {
        $reader = new InMemoryFinanceAccessScopeReader();
        $reader->grant(7, 'AP', 'mailbox', 'AP@Tricab.com');
        $scope = $reader->forUser(7, 'AP');

        self::assertSame(['ap@tricab.com'], $scope->mailboxes);
        self::assertTrue($scope->allowsMailbox(' ap@TRICAB.com '));
        self::assertFalse($scope->allowsMailbox('invoices@tricab.com'));
    }

    public function test_the_two_kinds_are_independent_and_a_scope_belongs_to_one_person_and_one_app(): void
    {
        $reader = new InMemoryFinanceAccessScopeReader();
        $reader->grant(7, 'AP', 'mailbox', 'ap@tricab.com');

        $scope = $reader->forUser(7, 'AP');
        self::assertTrue($scope->allowsBranch('Anywhere'), 'a mailbox scope does not limit branches');
        self::assertTrue($reader->forUser(8, 'AP')->isUnrestricted());
        self::assertTrue($reader->forUser(7, 'CREDIT')->isUnrestricted());
    }

    public function test_a_granted_value_is_not_listed_twice(): void
    {
        $reader = new InMemoryFinanceAccessScopeReader();
        $reader->grant(7, 'AP', 'mailbox', 'ap@tricab.com');
        $reader->grant(7, 'AP', 'mailbox', 'AP@tricab.com');

        self::assertSame(['ap@tricab.com'], $reader->forUser(7, 'AP')->mailboxes);
    }

    public function test_a_scope_must_be_a_known_kind_with_a_value(): void
    {
        $reader = new InMemoryFinanceAccessScopeReader();

        foreach ([['region', 'x'], ['branch', '   ']] as [$type, $value]) {
            try {
                $reader->grant(7, 'CREDIT', $type, $value);
                self::fail('Expected a refusal.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame(FinanceAccessScope::TYPES, ['branch', 'mailbox']);
    }
}
