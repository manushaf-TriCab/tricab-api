<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Slim\App;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Finance\Module;
use TriCab\Core\Identity\Contract\UserRepository;

/**
 * Every `/api/v1/finance*` route opens to exactly one permission, the one this
 * table names, and to no other. Copied from `RfqRouteTableTest`. Every later
 * finance task adds its routes here.
 *
 *  1. every route is found in the route table, and every finance route in the
 *     table is listed here, so a new route cannot arrive ungated;
 *  2. with only its permission, the route answers something other than 403;
 *  3. with every other finance permission, and platform permissions someone
 *     might plausibly hold, it answers 403.
 */
final class FinanceRouteTableTest extends TestCase
{
    private const SAMPLE_ID = '11111111-1111-4111-8111-111111111111';

    /** @var array<string, string> "METHOD pattern" => permission */
    private const ROUTES = [
        'GET /api/v1/finance/me' => Module::PERMISSION_ACCESS,
        'GET /api/v1/finance/admin/mailboxes' => Module::PERMISSION_ADMIN,
        'POST /api/v1/finance/reference/sync' => Module::PERMISSION_ADMIN,
        'GET /api/v1/finance/reference/status' => Module::PERMISSION_ADMIN,
        'GET /api/v1/finance/reference/{list}' => Module::PERMISSION_AP_ACCESS,
        // Tri-Task (Phase 2), with FINANCE_TASK_ENABLED on. One permission each (decisions D14, D15).
        'GET /api/v1/finance/task/me' => Module::PERMISSION_TASK_ACCESS,
        'GET /api/v1/finance/task/members' => Module::PERMISSION_TASK_TASKS_READ,
        'PATCH /api/v1/finance/task/members/{userId}/profile' => Module::PERMISSION_TASK_SETUP_MANAGE,
        'GET /api/v1/finance/task/projects' => Module::PERMISSION_TASK_TASKS_READ,
        'POST /api/v1/finance/task/projects' => Module::PERMISSION_TASK_SETUP_MANAGE,
        'PATCH /api/v1/finance/task/projects/{id}' => Module::PERMISSION_TASK_SETUP_MANAGE,
        'DELETE /api/v1/finance/task/projects/{id}' => Module::PERMISSION_TASK_SETUP_MANAGE,
        'POST /api/v1/finance/task/projects/{id}/restore' => Module::PERMISSION_TASK_SETUP_MANAGE,
        'GET /api/v1/finance/task/templates' => Module::PERMISSION_TASK_TASKS_READ,
        'POST /api/v1/finance/task/templates' => Module::PERMISSION_TASK_SETUP_MANAGE,
        'PATCH /api/v1/finance/task/templates/{id}' => Module::PERMISSION_TASK_SETUP_MANAGE,
        'DELETE /api/v1/finance/task/templates/{id}' => Module::PERMISSION_TASK_SETUP_MANAGE,
        'GET /api/v1/finance/task/statement-areas' => Module::PERMISSION_TASK_TASKS_READ,
        'POST /api/v1/finance/task/statement-areas' => Module::PERMISSION_TASK_SETUP_MANAGE,
        'POST /api/v1/finance/task/statement-areas/bulk' => Module::PERMISSION_TASK_SETUP_MANAGE,
        'PATCH /api/v1/finance/task/statement-areas/{id}' => Module::PERMISSION_TASK_SETUP_MANAGE,
        'DELETE /api/v1/finance/task/statement-areas/{id}' => Module::PERMISSION_TASK_SETUP_MANAGE,
        'GET /api/v1/finance/task/entities' => Module::PERMISSION_TASK_TASKS_READ,
        'POST /api/v1/finance/task/entities' => Module::PERMISSION_TASK_SETUP_MANAGE,
        'PATCH /api/v1/finance/task/entities/{id}' => Module::PERMISSION_TASK_SETUP_MANAGE,
        'DELETE /api/v1/finance/task/entities/{id}' => Module::PERMISSION_TASK_SETUP_MANAGE,
        // The project board (13 Oct 2026).
        'GET /api/v1/finance/task/projects/{id}/groups' => Module::PERMISSION_TASK_TASKS_READ,
        'POST /api/v1/finance/task/projects/{id}/groups' => Module::PERMISSION_TASK_SETUP_MANAGE,
        'PATCH /api/v1/finance/task/groups/{id}' => Module::PERMISSION_TASK_SETUP_MANAGE,
        'DELETE /api/v1/finance/task/groups/{id}' => Module::PERMISSION_TASK_SETUP_MANAGE,
        'POST /api/v1/finance/task/projects/{id}/task-order' => Module::PERMISSION_TASK_SETUP_MANAGE,
        'POST /api/v1/finance/task/projects/{id}/save-as-template' => Module::PERMISSION_TASK_SETUP_MANAGE,
        'GET /api/v1/finance/task/project-templates' => Module::PERMISSION_TASK_TASKS_READ,
        'POST /api/v1/finance/task/project-templates' => Module::PERMISSION_TASK_SETUP_MANAGE,
        'PATCH /api/v1/finance/task/project-templates/{id}' => Module::PERMISSION_TASK_SETUP_MANAGE,
        'DELETE /api/v1/finance/task/project-templates/{id}' => Module::PERMISSION_TASK_SETUP_MANAGE,
        'POST /api/v1/finance/task/project-templates/{id}/apply' => Module::PERMISSION_TASK_SETUP_MANAGE,
        // Project groups (Nick Han, 19 Oct 2026).
        'GET /api/v1/finance/task/project-groups' => Module::PERMISSION_TASK_TASKS_READ,
        'POST /api/v1/finance/task/project-groups' => Module::PERMISSION_TASK_SETUP_MANAGE,
        'PATCH /api/v1/finance/task/project-groups/{id}' => Module::PERMISSION_TASK_SETUP_MANAGE,
        'DELETE /api/v1/finance/task/project-groups/{id}' => Module::PERMISSION_TASK_SETUP_MANAGE,
        'GET /api/v1/finance/task/tasks' => Module::PERMISSION_TASK_TASKS_READ,
        'GET /api/v1/finance/task/tasks/{id}' => Module::PERMISSION_TASK_TASKS_READ,
        'POST /api/v1/finance/task/tasks' => Module::PERMISSION_TASK_TASKS_CREATE,
        'PATCH /api/v1/finance/task/tasks/{id}' => Module::PERMISSION_TASK_TASKS_UPDATE_OWN,
        'POST /api/v1/finance/task/tasks/bulk' => Module::PERMISSION_TASK_TASKS_UPDATE_OWN,
        // Several tasks into the Bin at once, and the undo (16 Oct 2026).
        'POST /api/v1/finance/task/tasks/bulk-delete' => Module::PERMISSION_TASK_TASKS_DELETE_OWN,
        'POST /api/v1/finance/task/tasks/bulk-restore' => Module::PERMISSION_TASK_TASKS_DELETE_OWN,
        'DELETE /api/v1/finance/task/tasks/{id}' => Module::PERMISSION_TASK_TASKS_DELETE_OWN,
        'POST /api/v1/finance/task/tasks/{id}/restore' => Module::PERMISSION_TASK_TASKS_DELETE_OWN,
        'POST /api/v1/finance/task/tasks/{id}/request-review' => Module::PERMISSION_TASK_TASKS_UPDATE_OWN,
        'GET /api/v1/finance/task/tasks/{id}/history' => Module::PERMISSION_TASK_TASKS_READ,
        'GET /api/v1/finance/task/history' => Module::PERMISSION_TASK_ANALYTICS_READ,
        'GET /api/v1/finance/task/analytics' => Module::PERMISSION_TASK_ANALYTICS_READ,
        'GET /api/v1/finance/task/send-mailboxes' => Module::PERMISSION_TASK_MAIL_SEND,
        'POST /api/v1/finance/task/mail/send' => Module::PERMISSION_TASK_MAIL_SEND,
        'GET /api/v1/finance/task/tasks/{id}/mail' => Module::PERMISSION_TASK_TASKS_READ,
        'GET /api/v1/finance/task/settings/report-recipients' => Module::PERMISSION_TASK_SETTINGS_MANAGE,
        'POST /api/v1/finance/task/settings/report-recipients' => Module::PERMISSION_TASK_SETTINGS_MANAGE,
        'DELETE /api/v1/finance/task/settings/report-recipients/{id}' => Module::PERMISSION_TASK_SETTINGS_MANAGE,
        'POST /api/v1/finance/task/import' => Module::PERMISSION_TASK_TASKS_CREATE,
        'POST /api/v1/finance/task/ai/task-details' => Module::PERMISSION_TASK_TASKS_CREATE,
        'POST /api/v1/finance/task/ai/statement-areas' => Module::PERMISSION_TASK_SETUP_MANAGE,
        'POST /api/v1/finance/task/ai/task-emails' => Module::PERMISSION_TASK_MAIL_SEND,
        'POST /api/v1/finance/task/ai/reminders' => Module::PERMISSION_TASK_TASKS_READ,
        'POST /api/v1/finance/task/ai/email-draft' => Module::PERMISSION_TASK_MAIL_SEND,
        // Tri-AR (Phase 5), with FINANCE_AR_ENABLED on. One permission each; the public credit-note form (T5.10) will be the only exception.
        'GET /api/v1/finance/ar/me' => Module::PERMISSION_AR_ACCESS,
        'GET /api/v1/finance/ar/assignees' => Module::PERMISSION_AR_RECORDS_READ,
        'GET /api/v1/finance/ar/settings/options' => Module::PERMISSION_AR_RECORDS_READ,
        'POST /api/v1/finance/ar/settings/options/{list}' => Module::PERMISSION_AR_ADMIN,
        'PUT /api/v1/finance/ar/settings/options/{list}/order' => Module::PERMISSION_AR_ADMIN,
        'PATCH /api/v1/finance/ar/settings/options/{list}/{value}' => Module::PERMISSION_AR_ADMIN,
        'DELETE /api/v1/finance/ar/settings/options/{list}/{value}' => Module::PERMISSION_AR_ADMIN,
        'GET /api/v1/finance/ar/records' => Module::PERMISSION_AR_RECORDS_READ,
        'GET /api/v1/finance/ar/records/{id}' => Module::PERMISSION_AR_RECORDS_READ,
        'PATCH /api/v1/finance/ar/records/{id}' => Module::PERMISSION_AR_RECORDS_WRITE,
        'DELETE /api/v1/finance/ar/records/{id}' => Module::PERMISSION_AR_RECORDS_WRITE,
        'POST /api/v1/finance/ar/records/{id}/restore' => Module::PERMISSION_AR_RECORDS_WRITE,
        'GET /api/v1/finance/ar/dashboard/metrics' => Module::PERMISSION_AR_RECORDS_READ,
        'GET /api/v1/finance/ar/records/{id}/body' => Module::PERMISSION_AR_RECORDS_READ,
        'POST /api/v1/finance/ar/records/{id}/email-draft' => Module::PERMISSION_AR_RECORDS_WRITE,
        'GET /api/v1/finance/ar/records/{id}/attachments/{aid}/file' => Module::PERMISSION_AR_ATTACHMENTS_READ,
        'GET /api/v1/finance/ar/credit-notes' => Module::PERMISSION_AR_RECORDS_READ,
        'GET /api/v1/finance/ar/credit-notes/{id}' => Module::PERMISSION_AR_RECORDS_READ,
        'POST /api/v1/finance/ar/credit-notes' => Module::PERMISSION_AR_RECORDS_WRITE,
        'PATCH /api/v1/finance/ar/credit-notes/{id}' => Module::PERMISSION_AR_RECORDS_WRITE,
        'POST /api/v1/finance/ar/credit-notes/{id}/resend' => Module::PERMISSION_AR_RECORDS_WRITE,
        'GET /api/v1/finance/ar/send-mailboxes' => Module::PERMISSION_AR_RECORDS_READ,
        'GET /api/v1/finance/ar/records/{id}/outbound-messages' => Module::PERMISSION_AR_RECORDS_READ,
        'POST /api/v1/finance/ar/records/{id}/send-response' => Module::PERMISSION_AR_RECORDS_WRITE,
        'POST /api/v1/finance/ar/scan' => Module::PERMISSION_AR_RECORDS_WRITE,
        'GET /api/v1/finance/ar/scan-runs' => Module::PERMISSION_AR_RECORDS_READ,
        // Tri-AP (Phase 6), with FINANCE_AP_ENABLED on. One permission each (question Q1).
        'GET /api/v1/finance/ap/me' => Module::PERMISSION_AP_ACCESS,
        'GET /api/v1/finance/ap/mailboxes' => Module::PERMISSION_AP_INVOICES_READ,
        'GET /api/v1/finance/ap/signing-authority' => Module::PERMISSION_AP_INVOICES_READ,
        // Tri-AP: vendors and extraction (T6.8, T6.9).
        'GET /api/v1/finance/ap/vendors' => Module::PERMISSION_AP_INVOICES_READ,
        'GET /api/v1/finance/ap/invoices/{id}/vendor-suggestions' => Module::PERMISSION_AP_INVOICES_READ,
        'PUT /api/v1/finance/ap/invoices/{id}/vendor-match' => Module::PERMISSION_AP_INVOICES_WRITE,
        'POST /api/v1/finance/ap/vendor-matching/run' => Module::PERMISSION_AP_INVOICES_WRITE,
        'POST /api/v1/finance/ap/extract' => Module::PERMISSION_AP_INVOICES_WRITE,
        'POST /api/v1/finance/ap/scan' => Module::PERMISSION_AP_MAIL_READ,
        // The register and its files (T6.4, T6.5). A permanent delete also needs INVOICES.DELETE, checked in the use case.
        'GET /api/v1/finance/ap/invoices' => Module::PERMISSION_AP_INVOICES_READ,
        'POST /api/v1/finance/ap/invoices' => Module::PERMISSION_AP_INVOICES_WRITE,
        'POST /api/v1/finance/ap/invoices/download' => Module::PERMISSION_AP_INVOICES_READ,
        'GET /api/v1/finance/ap/invoices/{id}' => Module::PERMISSION_AP_INVOICES_READ,
        'PATCH /api/v1/finance/ap/invoices/{id}' => Module::PERMISSION_AP_INVOICES_WRITE,
        'DELETE /api/v1/finance/ap/invoices/{id}' => Module::PERMISSION_AP_INVOICES_WRITE,
        'POST /api/v1/finance/ap/invoices/{id}/restore' => Module::PERMISSION_AP_INVOICES_WRITE,
        'GET /api/v1/finance/ap/invoices/{id}/file' => Module::PERMISSION_AP_INVOICES_READ,
        'POST /api/v1/finance/ap/uploads' => Module::PERMISSION_AP_INVOICES_WRITE,
        'GET /api/v1/finance/ap/companies' => Module::PERMISSION_AP_INVOICES_READ,
        'POST /api/v1/finance/ap/companies' => Module::PERMISSION_AP_SETTINGS_MANAGE,
        'PATCH /api/v1/finance/ap/companies/{id}' => Module::PERMISSION_AP_SETTINGS_MANAGE,
        'DELETE /api/v1/finance/ap/companies/{id}' => Module::PERMISSION_AP_SETTINGS_MANAGE,
        'GET /api/v1/finance/ap/cost-centres' => Module::PERMISSION_AP_INVOICES_READ,
        'POST /api/v1/finance/ap/cost-centres' => Module::PERMISSION_AP_SETTINGS_MANAGE,
        'PATCH /api/v1/finance/ap/cost-centres/{id}' => Module::PERMISSION_AP_SETTINGS_MANAGE,
        'DELETE /api/v1/finance/ap/cost-centres/{id}' => Module::PERMISSION_AP_SETTINGS_MANAGE,
        'GET /api/v1/finance/ap/account-categories' => Module::PERMISSION_AP_INVOICES_READ,
        'POST /api/v1/finance/ap/account-categories' => Module::PERMISSION_AP_SETTINGS_MANAGE,
        'PATCH /api/v1/finance/ap/account-categories/{id}' => Module::PERMISSION_AP_SETTINGS_MANAGE,
        'DELETE /api/v1/finance/ap/account-categories/{id}' => Module::PERMISSION_AP_SETTINGS_MANAGE,
        // Tri-AP: AP Mailbox (T6.6)
        'GET /api/v1/finance/ap/emails' => Module::PERMISSION_AP_MAIL_READ,
        'GET /api/v1/finance/ap/emails/stats' => Module::PERMISSION_AP_MAIL_READ,
        'POST /api/v1/finance/ap/emails/bulk' => Module::PERMISSION_AP_INVOICES_WRITE,
        'GET /api/v1/finance/ap/emails/{id}/body' => Module::PERMISSION_AP_MAIL_READ,
        'GET /api/v1/finance/ap/emails/{id}/attachments' => Module::PERMISSION_AP_MAIL_READ,
        'GET /api/v1/finance/ap/emails/{id}/attachments/{aid}/file' => Module::PERMISSION_AP_MAIL_READ,
        'PATCH /api/v1/finance/ap/emails/{id}' => Module::PERMISSION_AP_INVOICES_WRITE,
        // Tri-AP: mail (T6.10)
        'GET /api/v1/finance/ap/send-mailboxes' => Module::PERMISSION_AP_INVOICES_READ,
        'POST /api/v1/finance/ap/invoices/{id}/email-draft' => Module::PERMISSION_AP_MAIL_SEND,
        'POST /api/v1/finance/ap/emails/{id}/email-draft' => Module::PERMISSION_AP_MAIL_SEND,
        'POST /api/v1/finance/ap/invoices/{id}/send-email' => Module::PERMISSION_AP_MAIL_SEND,
        'POST /api/v1/finance/ap/emails/{id}/send-reply' => Module::PERMISSION_AP_MAIL_SEND,
        'GET /api/v1/finance/ap/invoices/{id}/outbound-messages' => Module::PERMISSION_AP_INVOICES_READ,
        'GET /api/v1/finance/ap/emails/{id}/outbound-messages' => Module::PERMISSION_AP_INVOICES_READ,
        // Tri-AP: approvals (T6.11)
        'GET /api/v1/finance/ap/approvers' => Module::PERMISSION_AP_INVOICES_READ,
        'GET /api/v1/finance/ap/approval-batches' => Module::PERMISSION_AP_INVOICES_READ,
        'POST /api/v1/finance/ap/approval-batches' => Module::PERMISSION_AP_APPROVALS_PREPARE,
        'GET /api/v1/finance/ap/approval-batches/{id}' => Module::PERMISSION_AP_INVOICES_READ,
        'POST /api/v1/finance/ap/approval-batches/{id}/decision' => Module::PERMISSION_AP_APPROVALS_DECIDE,
    ];

    /** Platform permissions that must never stand in for a finance one. */
    private const UNRELATED = ['ACCOUNT.LOGIN', 'RFQ.ACCESS', 'RFQ.ADMIN', 'QUOTE.READ', 'CUSTOMER.READ', 'PRODUCT.READ', 'ONBOARDING.SELF.READ'];

    /**
     * The only finance routes with no permission, by design (question Q7): the sales reps' credit-note form. A token,
     * its expiry, single use and a rate limit stand in for sign-in. A new route cannot join this list unnoticed.
     */
    private const PUBLIC_ROUTES = [
        'GET /api/v1/finance/ar/public/credit-note-form',
        'POST /api/v1/finance/ar/public/credit-note-form',
    ];

    public function test_the_credit_note_form_routes_are_the_only_finance_routes_with_no_permission(): void
    {
        self::assertSame([], array_intersect(self::PUBLIC_ROUTES, array_keys(self::ROUTES)), 'a public route is not in the permission table');
        foreach (self::PUBLIC_ROUTES as $route) {
            [$method, $pattern] = explode(' ', $route, 2);
            // No permission at all, and no sign-in is needed to reach the form's own logic (a 422 for the missing link parts).
            [$status, $code] = $this->responseFor($method, $pattern, []);
            self::assertSame([422, 'FINANCE_AR_INPUT_INVALID'], [$status, $code], $route);
        }
        $registered = [];
        foreach ($this->app()->getRouteCollector()->getRoutes() as $route) {
            if (!str_starts_with($route->getPattern(), '/api/v1/finance')) {
                continue;
            }
            foreach ($route->getMethods() as $method) {
                $registered[] = $method . ' ' . $route->getPattern();
            }
        }
        $unguarded = array_values(array_diff($registered, array_keys(self::ROUTES)));
        sort($unguarded);
        $expected = self::PUBLIC_ROUTES;
        sort($expected);
        self::assertSame($expected, $unguarded, 'every other finance route is in the permission table');
    }

    public function test_every_finance_route_is_in_the_table(): void
    {
        $registered = [];
        foreach ($this->app()->getRouteCollector()->getRoutes() as $route) {
            if (!str_starts_with($route->getPattern(), '/api/v1/finance')) {
                continue;
            }
            foreach ($route->getMethods() as $method) {
                $registered[] = $method . ' ' . $route->getPattern();
            }
        }
        sort($registered);
        $expected = [...array_keys(self::ROUTES), ...self::PUBLIC_ROUTES];
        sort($expected);

        self::assertSame($expected, $registered);
    }

    public function test_each_route_opens_to_its_permission_alone(): void
    {
        foreach (self::ROUTES as $route => $permission) {
            [$method, $pattern] = explode(' ', $route, 2);
            $path = str_replace(['{publicId}', '{list}', '{userId}', '{id}', '{aid}', '{value}'], [self::SAMPLE_ID, 'vendors', '5', self::SAMPLE_ID, self::SAMPLE_ID, 'x'], $pattern);

            // A 403 from the app's own rule (`FINANCE_AR_FORBIDDEN` or `FINANCE_AP_FORBIDDEN`: no mailbox grant) is behind the gate, not the gate.
            [$with, $code] = $this->responseFor($method, $path, ['account.login', $permission]);
            self::assertTrue($with !== 403 || in_array($code, ['FINANCE_AR_FORBIDDEN', 'FINANCE_AP_FORBIDDEN'], true), $route . ' refused its own permission.');

            $others = array_values(array_diff(
                [...self::everyFinancePermission(), ...self::UNRELATED],
                [$permission],
            ));
            self::assertSame(403, $this->statusFor($method, $path, $others), $route . ' opened without ' . $permission . '.');
        }
    }

    /**
     * @return list<string>
     */
    private static function everyFinancePermission(): array
    {
        $all = [Module::PERMISSION_ACCESS, Module::PERMISSION_ADMIN, ...Module::TASK_PERMISSIONS, ...Module::AR_PERMISSIONS, ...Module::AP_PERMISSIONS];
        foreach (array_keys(Module::APPS) as $key) {
            $all[] = Module::appAccessPermission($key);
        }

        return $all;
    }

    /**
     * @return App<ContainerInterface>
     */
    private function app(): App
    {
        return TestAppFactory::create(['FINANCE_MODULE_ENABLED' => 'true', 'FINANCE_TASK_ENABLED' => 'true', 'FINANCE_AR_ENABLED' => 'true', 'FINANCE_AP_ENABLED' => 'true']);
    }

    /**
     * @param list<string> $permissions
     */
    private function statusFor(string $method, string $path, array $permissions): int
    {
        return $this->responseFor($method, $path, $permissions)[0];
    }

    /**
     * @param list<string> $permissions
     * @return array{0: int, 1: string} the status and the error code ('' when there is none)
     */
    private function responseFor(string $method, string $path, array $permissions): array
    {
        $app = $this->app();
        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $users = $container->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $users);
        $users->replacePermissions(1, $permissions, ['EMPLOYEE']);

        $response = $app->handle(TestAppFactory::request($method, $path));
        $error = TestAppFactory::json($response)['error'] ?? null;
        $code = is_array($error) && is_string($error['code'] ?? null) ? $error['code'] : '';

        return [$response->getStatusCode(), $code];
    }
}
