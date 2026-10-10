<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Finance\Ap\Domain\ApActor;
use TriCab\Core\Finance\Ap\Domain\ApMailboxScope;
use TriCab\Core\Finance\Ap\Domain\ApNotFound;

/** The mailbox-scope helper every later Tri-AP task calls (question Q11): "out of scope is a 404". */
final class ApMailboxScopeTest extends TestCase
{
    public function test_no_restriction_sees_every_mailbox_and_every_record(): void
    {
        $scope = ApMailboxScope::unrestricted();

        self::assertFalse($scope->isRestricted());
        self::assertNull($scope->mailboxes());
        self::assertTrue($scope->allowsMailbox('anyone@example.test'));
        self::assertTrue($scope->allowsRecord('anyone@example.test'));
        $scope->requireRecord('anyone@example.test');
    }

    public function test_an_empty_list_of_rows_is_no_restriction_as_in_the_portal(): void
    {
        self::assertFalse(ApMailboxScope::limitedTo([])->isRestricted());
        self::assertFalse(ApMailboxScope::limitedTo(['', '  '])->isRestricted());
    }

    public function test_a_restriction_is_exactly_those_mailboxes_in_any_case_plus_hand_captures(): void
    {
        $scope = ApMailboxScope::limitedTo(['AP@Example.test', ' christine@example.test ', 'ap@example.test']);

        self::assertTrue($scope->isRestricted());
        self::assertSame(['ap@example.test', 'christine@example.test'], $scope->mailboxes());
        self::assertTrue($scope->allowsMailbox('Christine@example.test'));
        self::assertFalse($scope->allowsMailbox('other@example.test'));
        self::assertTrue($scope->allowsRecord('AP@example.test'));
        self::assertFalse($scope->allowsRecord('other@example.test'));
        self::assertTrue($scope->allowsRecord(null), 'a hand capture has no mailbox and is visible to everyone');
        self::assertTrue($scope->allowsRecord(''));
        self::assertTrue($scope->allowsRecord('   '));
        self::assertFalse($scope->allowsMailbox(''), 'a mailbox in the registry always has an address');
    }

    public function test_out_of_scope_is_a_not_found(): void
    {
        $scope = ApMailboxScope::limitedTo(['ap@example.test']);
        $scope->requireRecord('ap@example.test');
        $scope->requireRecord(null);

        try {
            $scope->requireRecord('other@example.test');
            self::fail('An out-of-scope record should be a 404.');
        } catch (ApNotFound $failure) {
            self::assertSame(404, $failure->httpStatus());
            self::assertSame('FINANCE_AP_NOT_FOUND', $failure->errorCode());
            self::assertSame('Not found', $failure->getMessage());
        }
    }

    public function test_an_actor_defaults_to_unrestricted_and_remembers_who_and_what_it_may_do(): void
    {
        $actor = new ApActor(7, 'Una User', 'UNA@example.test', ['FINANCE.AP.INVOICES.READ']);

        self::assertFalse($actor->mailboxScope->isRestricted());
        self::assertFalse($actor->isAdmin);
        self::assertTrue($actor->can('FINANCE.AP.INVOICES.READ'));
        self::assertFalse($actor->can('FINANCE.AP.INVOICES.WRITE'));
        self::assertSame('una@example.test', $actor->who());
        self::assertSame('Una User', (new ApActor(7, 'Una User', '', []))->who());
    }
}
