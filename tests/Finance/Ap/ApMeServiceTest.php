<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Finance\Ap\Domain\ApActor;
use TriCab\Core\Finance\Ap\Domain\ApMailboxScope;
use TriCab\Core\Finance\Ap\Service\ApMeService;
use TriCab\Core\Finance\Module;

/** `GET /me` on plain values (T6.3, decision D78). */
final class ApMeServiceTest extends TestCase
{
    public function test_me_answers_the_portals_permission_strings_sorted_and_the_seven_keys(): void
    {
        $actor = new ApActor(12, 'Mia Operator', 'Mia@Example.test', [
            Module::PERMISSION_ACCESS,
            Module::PERMISSION_AP_ACCESS,
            Module::PERMISSION_AP_MAIL_READ,
            Module::PERMISSION_AP_INVOICES_WRITE,
            Module::PERMISSION_AP_INVOICES_READ,
        ]);

        $me = (new ApMeService())->me($actor);

        self::assertSame(
            ['id', 'email', 'full_name', 'permissions', 'role', 'finance_role', 'allowed_mailboxes', 'sending_enabled'],
            array_keys($me),
        );
        self::assertSame('12', $me['id']);
        self::assertSame('mia@example.test', $me['email']);
        self::assertSame('Mia Operator', $me['full_name']);
        self::assertSame(['invoice.invoices.read', 'invoice.invoices.write', 'invoice.mail.read'], $me['permissions'], 'FINANCE.ACCESS and FINANCE.AP.ACCESS have no portal string');
        self::assertSame('user', $me['role']);
        self::assertSame('operator', $me['finance_role']);
        self::assertNull($me['allowed_mailboxes'], 'no scope rows is every mailbox');
        self::assertFalse($me['sending_enabled']);
    }

    public function test_the_administrator_is_role_admin_and_holds_every_portal_string(): void
    {
        $actor = new ApActor(3, '', 'ada@example.test', [Module::PERMISSION_AP_ACCESS, ...Module::AP_PERMISSIONS], null, true);

        $me = (new ApMeService())->me($actor);

        self::assertSame('admin', $me['role']);
        self::assertSame('ada@example.test', $me['full_name'], 'a nameless person shows their address');
        $permissions = $me['permissions'];
        self::assertIsArray($permissions);
        self::assertCount(count(Module::AP_PORTAL_PERMISSIONS), $permissions);
        self::assertContains('invoice.settings.manage', $permissions);
        self::assertSame('approver', $me['finance_role'], 'the approver permission wins');
    }

    public function test_finance_role_is_approver_then_preparer_then_operator(): void
    {
        $service = new ApMeService();

        self::assertSame('approver', $service->me(new ApActor(1, 'A', 'a@example.test', [Module::PERMISSION_AP_APPROVALS_PREPARE, Module::PERMISSION_AP_APPROVALS_DECIDE]))['finance_role']);
        self::assertSame('preparer', $service->me(new ApActor(1, 'A', 'a@example.test', [Module::PERMISSION_AP_APPROVALS_PREPARE]))['finance_role']);
        self::assertSame('operator', $service->me(new ApActor(1, 'A', 'a@example.test', [Module::PERMISSION_AP_INVOICES_READ]))['finance_role']);
        self::assertSame('operator', $service->me(new ApActor(1, 'A', 'a@example.test', []))['finance_role']);
    }

    public function test_a_person_with_the_app_but_no_invoice_permission_gets_an_empty_list_not_a_refusal(): void
    {
        $me = (new ApMeService())->me(new ApActor(4, 'Nina', 'nina@example.test', [Module::PERMISSION_AP_ACCESS]));

        self::assertSame([], $me['permissions']);
    }

    public function test_allowed_mailboxes_are_the_scope_rows_lower_cased_and_sorted(): void
    {
        $scope = ApMailboxScope::limitedTo(['Christine@Example.test', 'ap@example.test']);
        $me = (new ApMeService())->me(new ApActor(5, 'Una', 'una@example.test', [], $scope));

        self::assertSame(['ap@example.test', 'christine@example.test'], $me['allowed_mailboxes']);
    }

    public function test_me_says_whether_sending_is_switched_on_and_it_is_off_unless_told_otherwise(): void
    {
        $actor = new ApActor(1, 'A', 'a@example.test', []);

        self::assertFalse((new ApMeService())->me($actor)['sending_enabled']);
        self::assertFalse((new ApMeService(false))->me($actor)['sending_enabled']);
        self::assertTrue((new ApMeService(true))->me($actor)['sending_enabled']);
    }
}
