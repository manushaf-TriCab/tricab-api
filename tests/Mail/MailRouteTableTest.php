<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Mail;

use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Mail\Module;

/**
 * Every `/api/v1/mail*` route opens to exactly one permission, the one this table names, and to nobody
 * without it. Copied from `FinanceRouteTableTest`:
 *
 *  1. every mail route in the route table is listed here, and every listed route exists, so a new route
 *     cannot arrive ungated;
 *  2. with its permission (and MAIL.ACCESS, which the export's service also needs), a route answers something other than 403;
 *  3. without `MAIL.ACCESS`, whatever else someone holds (including `MAIL.REVIEW`), every route is 403.
 */
final class MailRouteTableTest extends MailAppTestCase
{
    private const ID = '0123456789abcdef0123456789abcdef';

    /** @var array<string, string> "METHOD pattern" => permission */
    private const ROUTES = [
        'GET /api/v1/mail/me' => Module::PERMISSION_ACCESS,
        // The labelled set: every reviewer may build it. The service also needs MAIL.ACCESS, like every route here.
        'GET /api/v1/mail/export' => Module::PERMISSION_REVIEW,
        // The M5 gate numbers: the labelled set's summary, so the same reviewers as the export.
        'GET /api/v1/mail/accuracy' => Module::PERMISSION_REVIEW,
        'GET /api/v1/mail/queues' => Module::PERMISSION_ACCESS,
        'GET /api/v1/mail/threads' => Module::PERMISSION_ACCESS,
        'GET /api/v1/mail/threads/{id}' => Module::PERMISSION_ACCESS,
        // Marking read needs no more than access; the service demands MAIL.REVIEW for every other action.
        'POST /api/v1/mail/threads/{id}/review' => Module::PERMISSION_ACCESS,
        // Deleting a conversation is a reviewer's; the service also needs MAIL.ACCESS.
        'DELETE /api/v1/mail/threads/{id}' => Module::PERMISSION_REVIEW,
        'GET /api/v1/mail/messages/{id}/body' => Module::PERMISSION_ACCESS,
        'GET /api/v1/mail/attachments/{id}' => Module::PERMISSION_ACCESS,
    ];

    /** @return list<string> */
    private function registered(): array
    {
        $found = [];
        foreach ($this->app->getRouteCollector()->getRoutes() as $route) {
            if (str_starts_with($route->getPattern(), '/api/v1/mail')) {
                foreach ($route->getMethods() as $method) {
                    $found[] = $method . ' ' . $route->getPattern();
                }
            }
        }
        sort($found);

        return $found;
    }

    /** @return array{0: string, 1: array<string, mixed>|null} */
    private static function request(string $key): array
    {
        [$method, $pattern] = explode(' ', $key, 2);

        return [$method . ' ' . str_replace('{id}', self::ID, $pattern), $method === 'POST' ? ['action' => 'mark_read'] : null];
    }

    public function test_every_mail_route_is_in_the_table_and_the_table_has_no_other(): void
    {
        $this->boot([Module::PERMISSION_ACCESS]);
        $expected = array_keys(self::ROUTES);
        sort($expected);

        self::assertSame($expected, $this->registered());
    }

    public function test_with_only_its_permission_no_route_answers_403(): void
    {
        foreach (self::ROUTES as $key => $permission) {
            $this->boot(array_values(array_unique([Module::PERMISSION_ACCESS, $permission])));
            [$line, $body] = self::request($key);
            [$method, $path] = explode(' ', $line, 2);

            self::assertNotSame(403, $this->call($method, $path, $body)->getStatusCode(), $key);
        }
    }

    public function test_without_access_every_route_is_403_whatever_else_is_held(): void
    {
        $others = [Module::PERMISSION_REVIEW, 'RFQ.ACCESS', 'RFQ.DRAFT.READ', 'RFQ.ADMIN', 'FINANCE.ACCESS', 'FINANCE.ADMIN', 'TEL_DIRECTORY.ACCESS'];
        foreach ([[], $others] as $held) {
            $this->boot($held);
            foreach (array_keys(self::ROUTES) as $key) {
                [$line, $body] = self::request($key);
                [$method, $path] = explode(' ', $line, 2);
                $response = $this->call($method, $path, $body);

                self::assertSame(403, $response->getStatusCode(), $key . ' with ' . implode(',', $held));
                self::assertFalse(TestAppFactory::json($response)['success']);
            }
        }
    }

    public function test_off_by_default_there_is_no_mail_route_and_me_says_false(): void
    {
        $this->boot([Module::PERMISSION_ACCESS], ['MAIL_MODULE_ENABLED' => 'false']);

        self::assertSame([], $this->registered());
        self::assertSame(404, $this->call('GET', '/api/v1/mail/me')->getStatusCode());
        self::assertFalse($this->modules()['inbox'] ?? null);
    }

    public function test_on_me_says_true_only_for_someone_holding_access(): void
    {
        $this->boot([Module::PERMISSION_ACCESS]);
        self::assertTrue($this->modules()['inbox'] ?? null);

        $this->boot([Module::PERMISSION_REVIEW]);
        self::assertFalse($this->modules()['inbox'] ?? null);
    }

    /** @return array<mixed> */
    private function modules(): array
    {
        $data = $this->data('/api/v1/me');
        self::assertIsArray($data['modules']);

        return $data['modules'];
    }
}
