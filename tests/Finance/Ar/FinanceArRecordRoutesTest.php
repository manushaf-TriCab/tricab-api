<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ar;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Slim\App;
use TriCab\Api\Finance\Ar\InMemoryArAssigneeDirectory;
use TriCab\Api\Finance\Ar\InMemoryArStore;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Finance\Ar\Contract\ArAssigneeDirectory;
use TriCab\Core\Finance\Ar\Domain\ArRecord;
use TriCab\Core\Finance\Module;
use TriCab\Core\Identity\Contract\UserRepository;

/**
 * The register's six routes through the real app on the in-memory stores: the envelope, statuses and
 * codes, the PATCH whitelist, and who may write. Nothing here reaches a mailbox, a model, Odoo or a database.
 */
final class FinanceArRecordRoutesTest extends TestCase
{
    private const BASE = '/api/v1/finance/ar';

    /** @var App<ContainerInterface> */
    private App $app;

    private InMemoryArStore $store;

    protected function setUp(): void
    {
        $this->app = TestAppFactory::create(['FINANCE_MODULE_ENABLED' => 'true', 'FINANCE_AR_ENABLED' => 'true']);
        $store = $this->app->getContainer()->get(InMemoryArStore::class);
        self::assertInstanceOf(InMemoryArStore::class, $store);
        $this->store = $store;
    }

    /**
     * @param list<string> $permissions
     */
    private function grant(array $permissions): void
    {
        $users = $this->app->getContainer()->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $users);
        $users->replacePermissions(1, ['account.login', Module::PERMISSION_ACCESS, ...$permissions], ['EMPLOYEE']);
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function call(string $method, string $path, ?array $body = null): array
    {
        $request = TestAppFactory::request($method, self::BASE . $path);
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }
        $response = $this->app->handle($request);

        return [$response->getStatusCode(), TestAppFactory::json($response)];
    }

    private function add(string $id, mixed ...$over): ArRecord
    {
        $record = ArFixtures::record($id, ...$over);
        $this->store->insert($record);

        return $record;
    }

    /**
     * @param array<string, mixed> $json
     * @return array<string, mixed>
     */
    private static function data(array $json): array
    {
        $data = $json['data'];
        self::assertIsArray($data);
        $out = [];
        foreach ($data as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $json
     * @return array<string, mixed>
     */
    private static function error(array $json): array
    {
        $error = $json['error'];
        self::assertIsArray($error);
        $out = [];
        foreach ($error as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    public function test_the_list_is_in_the_platform_envelope_with_the_pages_keys(): void
    {
        $this->grant([Module::PERMISSION_AR_RECORDS_READ]);
        $this->add('a', subject: 'First');
        $this->add('b', subject: 'Second', emailDate: ArFixtures::utc('2026-09-22 00:00:00'));

        [$status, $json] = $this->call('GET', '/records');

        self::assertSame(200, $status);
        self::assertTrue($json['success']);
        $data = self::data($json);
        self::assertSame(2, $data['total']);
        self::assertSame(100, $data['limit']);
        self::assertSame(0, $data['offset']);
        $records = ArFixtures::rows($data['records']);
        self::assertSame(['b', 'a'], array_column($records, 'id'), 'newest first');
        self::assertArrayNotHasKey('body', $records[0]);
    }

    public function test_query_arguments_reach_the_service_and_a_bad_one_is_a_422_with_the_ar_code(): void
    {
        $this->grant([Module::PERMISSION_AR_RECORDS_READ]);
        $this->add('a', subject: 'Freight', emailDate: ArFixtures::utc('2026-05-14 14:30:00'));
        $this->add('b', subject: 'Other', emailDate: ArFixtures::utc('2026-06-14 14:30:00'));

        [, $json] = $this->call('GET', '/records?search=freight&from_date=2026-05-15&to_date=2026-05-15&sort_by=subject&sort_dir=asc&limit=5');
        $data = self::data($json);
        self::assertSame(1, $data['total']);
        self::assertSame(5, $data['limit']);

        [$status, $json] = $this->call('GET', '/records?from_date=15/05/2026');
        self::assertSame(422, $status);
        $error = self::error($json);
        self::assertSame('FINANCE_AR_INPUT_INVALID', $error['code']);
        self::assertSame('Dates must use YYYY-MM-DD', $error['message']);

        [$status] = $this->call('GET', '/records?limit=zero');
        self::assertSame(422, $status);
        [, $json] = $this->call('GET', '/records?limit=999999');
        self::assertSame(1000, self::data($json)['limit']);
    }

    public function test_one_record_has_its_body_and_a_missing_one_is_a_404(): void
    {
        $this->grant([Module::PERMISSION_AR_RECORDS_READ]);
        $this->add('a', body: 'Hello body');

        [$status, $json] = $this->call('GET', '/records/a');
        self::assertSame(200, $status);
        $record = self::data($json);
        self::assertSame('Hello body', $record['body']);
        self::assertSame([], $record['attachments']);

        [$status, $json] = $this->call('GET', '/records/missing');
        self::assertSame(404, $status);
        self::assertSame('FINANCE_AR_NOT_FOUND', self::error($json)['code']);
        self::assertSame('Record not found', self::error($json)['message']);
    }

    public function test_patch_edits_the_pills_and_ignores_what_a_person_may_not_edit(): void
    {
        $this->grant([Module::PERMISSION_AR_RECORDS_WRITE]);
        $this->add('a', body: 'Original', mailbox: 'ar@tricab.com');

        [$status, $json] = $this->call('PATCH', '/records/a', ['follow_up_category' => 'credit_notes', 'final_outcome' => 'Done', 'body' => 'Rewritten', 'mailbox' => 'x@example.test']);

        self::assertSame(200, $status);
        $record = self::data($json);
        self::assertSame('credit_notes', $record['follow_up_category']);
        self::assertSame('Done', $record['final_outcome']);
        self::assertSame('Original', $record['body']);
        self::assertSame('ar@tricab.com', $record['mailbox']);

        [$status, $json] = $this->call('PATCH', '/records/a', ['follow_up_category' => 'not_a_category']);
        self::assertSame(422, $status);
        self::assertSame("Invalid follow_up_category: 'not_a_category'", self::error($json)['message']);
        [$status] = $this->call('PATCH', '/records/a', ['follow_up_status' => 'overdue']);
        self::assertSame(422, $status);
        [$status] = $this->call('PATCH', '/records/missing', ['status' => 'sent']);
        self::assertSame(404, $status);
    }

    public function test_an_overdue_date_shows_on_the_next_read_with_the_stored_status_untouched(): void
    {
        $this->grant([Module::PERMISSION_AR_RECORDS_READ, Module::PERMISSION_AR_RECORDS_WRITE]);
        $this->add('a');

        [, $json] = $this->call('PATCH', '/records/a', ['due_date' => '2000-01-01']);
        self::assertSame('overdue', self::data($json)['follow_up_status']);

        [, $json] = $this->call('GET', '/records?follow_up_status=overdue');
        self::assertSame(1, self::data($json)['total']);
        self::assertSame('in_progress', $this->store->stored('a')?->followUpStatus);
        [, $json] = $this->call('GET', '/dashboard/metrics');
        self::assertSame(1, self::data($json)['overdue_items']);
    }

    public function test_the_bin_cycle_through_the_routes(): void
    {
        $this->grant([Module::PERMISSION_AR_RECORDS_READ, Module::PERMISSION_AR_RECORDS_WRITE]);
        $this->add('a', sourceMessageId: 'graph-1');

        [$status, $json] = $this->call('DELETE', '/records/a?permanent=1');
        self::assertSame(409, $status);
        self::assertSame('FINANCE_AR_CONFLICT', self::error($json)['code']);

        [$status, $json] = $this->call('DELETE', '/records/a');
        self::assertSame(200, $status);
        $data = self::data($json);
        self::assertTrue($data['in_bin']);
        $binned = $data['record'];
        self::assertIsArray($binned);
        self::assertSame('thilanh@tricab.com', $binned['deleted_by'], 'the signed-in person\'s address');

        [, $json] = $this->call('GET', '/records');
        self::assertSame(0, self::data($json)['total']);
        [, $json] = $this->call('GET', '/records?deleted=1');
        self::assertSame(1, self::data($json)['total']);
        [, $json] = $this->call('GET', '/dashboard/metrics');
        self::assertSame(1, self::data($json)['in_bin']);

        [$status, $json] = $this->call('POST', '/records/a/restore');
        self::assertSame(200, $status);
        self::assertNull(self::data($json)['deleted_at']);

        $this->call('DELETE', '/records/a');
        [$status, $json] = $this->call('DELETE', '/records/a?permanent=true');
        self::assertSame(200, $status);
        self::assertSame(['in_bin' => false, 'deleted' => true], self::data($json));
        self::assertSame(['graph-1'], array_keys($this->store->tombstones()));
        [$status] = $this->call('GET', '/records/a');
        self::assertSame(404, $status);
    }

    public function test_the_metrics_follow_the_period_and_empty_maps_are_objects(): void
    {
        $this->grant([Module::PERMISSION_AR_RECORDS_READ]);

        $response = $this->app->handle(TestAppFactory::request('GET', self::BASE . '/dashboard/metrics'));
        self::assertStringContainsString('"category_counts":{}', (string) $response->getBody());
        self::assertStringContainsString('"credit_note_counts":{}', (string) $response->getBody());

        $this->add('m', emailDate: ArFixtures::utc('2026-03-02 08:00:00'), followUpStatus: 'needs_review');
        $this->add('j', emailDate: ArFixtures::utc('2026-06-15 08:00:00'));
        [, $json] = $this->call('GET', '/dashboard/metrics?from_date=2026-03-01&to_date=2026-03-31');
        $data = self::data($json);
        self::assertSame(1, $data['total_emails']);
        self::assertSame(1, $data['needs_review']);
        [$status, $json] = $this->call('GET', '/dashboard/metrics?from_date=March');
        self::assertSame(422, $status);
        self::assertFalse($json['success']);
    }

    public function test_a_reader_may_read_but_not_write_and_a_writer_alone_may_not_read(): void
    {
        $this->add('a');
        $this->grant([Module::PERMISSION_AR_RECORDS_READ]);
        foreach ([['PATCH', '/records/a'], ['DELETE', '/records/a'], ['POST', '/records/a/restore']] as [$method, $path]) {
            [$status] = $this->call($method, $path, $method === 'PATCH' ? ['status' => 'sent'] : null);
            self::assertSame(403, $status, $method . ' ' . $path);
        }
        self::assertSame('new', $this->store->stored('a')?->status);

        $this->grant([Module::PERMISSION_AR_RECORDS_WRITE]);
        foreach (['/records', '/records/a', '/dashboard/metrics'] as $path) {
            [$status] = $this->call('GET', $path);
            self::assertSame(403, $status, $path);
        }
    }

    public function test_assigning_a_person_through_the_route(): void
    {
        $this->grant([Module::PERMISSION_AR_RECORDS_WRITE]);
        $directory = $this->app->getContainer()->get(ArAssigneeDirectory::class);
        self::assertInstanceOf(InMemoryArAssigneeDirectory::class, $directory);
        $directory->add(12, 'Mia Operator', 'mia@example.test');
        $this->add('a');

        [, $json] = $this->call('PATCH', '/records/a', ['assigned_person' => 'Mia Operator']);

        $record = self::data($json);
        self::assertSame('Mia Operator', $record['assigned_person']);
        self::assertSame('12', $record['assigned_user_id']);
        [$status] = $this->call('PATCH', '/records/a', ['assigned_person' => 'Nobody']);
        self::assertSame(422, $status);
    }
}
