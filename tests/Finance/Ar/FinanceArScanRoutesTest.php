<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ar;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Finance\Ar\Service\ArMailboxScanJob;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Shared\Jobs\FinanceJobRegistry;
use TriCab\Core\Identity\Contract\UserRepository;

/**
 * The scan routes (T5.7) through the real app on the in-memory stores, with mailbox reading off (the default), so
 * nothing reaches Graph: the gates, the typed refusals, a failed run left behind as history rather than as a held
 * claim, and the daily job's two switches. The scan itself is covered on the twins in `ArScanServiceTest`.
 */
final class FinanceArScanRoutesTest extends TestCase
{
    private const BASE = '/api/v1/finance/ar';
    private const GRAPH = ['FINANCE_GRAPH_TENANT_ID' => 't', 'FINANCE_GRAPH_CLIENT_ID' => 'c', 'FINANCE_GRAPH_CLIENT_SECRET' => 's'];

    /** @var App<ContainerInterface> */
    private App $app;

    protected function setUp(): void
    {
        $this->app = TestAppFactory::create(['FINANCE_MODULE_ENABLED' => 'true', 'FINANCE_AR_ENABLED' => 'true']);
    }

    /**
     * @param list<string> $permissions
     * @param array<string, mixed>|null $body
     */
    private function call(string $method, string $path, array $permissions, ?array $body = null): ResponseInterface
    {
        $users = $this->app->getContainer()->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $users);
        $users->replacePermissions(1, ['account.login', Module::PERMISSION_ACCESS, ...$permissions], ['EMPLOYEE']);
        $request = TestAppFactory::request($method, self::BASE . $path);
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }

        return $this->app->handle($request);
    }

    /**
     * @return array<string, mixed>
     */
    private static function errorOf(ResponseInterface $response): array
    {
        $error = TestAppFactory::json($response)['error'] ?? null;
        self::assertIsArray($error);
        $typed = [];
        foreach ($error as $key => $value) {
            $typed[(string) $key] = $value;
        }

        return $typed;
    }

    /**
     * @param array<string, string> $env
     * @return list<string>
     */
    private function jobNames(array $env): array
    {
        $app = TestAppFactory::create($env);
        $registry = $app->getContainer()->get(FinanceJobRegistry::class);
        self::assertInstanceOf(FinanceJobRegistry::class, $registry);

        return array_map(static fn ($job): string => $job->name(), $registry->all());
    }

    public function test_scanning_needs_the_write_permission_and_the_history_the_read_permission(): void
    {
        self::assertSame(403, $this->call('POST', '/scan', [Module::PERMISSION_AR_RECORDS_READ], [])->getStatusCode());
        self::assertSame(403, $this->call('GET', '/scan-runs', [])->getStatusCode());
        $runs = $this->call('GET', '/scan-runs', [Module::PERMISSION_AR_RECORDS_READ]);
        self::assertSame(200, $runs->getStatusCode());
        self::assertSame(['success' => true, 'data' => ['runs' => []]], TestAppFactory::json($runs));
    }

    public function test_the_sources_input_refusals_are_422_with_the_ar_code(): void
    {
        $cases = [
            [['mode' => 'list'], 'fromDate and toDate are required'],
            [['mode' => 'list', 'fromDate' => '2026-10-06', 'toDate' => '2026-10-05'], 'From date cannot be after to date'],
            [['mode' => 'process'], 'messageId is required'],
            [['mode' => 'sideways'], 'Unknown mode: sideways'],
        ];
        foreach ($cases as [$body, $message]) {
            $response = $this->call('POST', '/scan', [Module::PERMISSION_AR_RECORDS_WRITE], $body);
            self::assertSame(422, $response->getStatusCode(), $message);
            $error = self::errorOf($response);
            self::assertSame('FINANCE_AR_INPUT_INVALID', $error['code']);
            self::assertSame($message, $error['message']);
        }
    }

    public function test_completing_a_run_that_does_not_exist_is_a_404(): void
    {
        $response = $this->call('POST', '/scan', [Module::PERMISSION_AR_RECORDS_WRITE], ['mode' => 'complete', 'scanRunId' => 'nope']);

        self::assertSame(404, $response->getStatusCode());
        $error = self::errorOf($response);
        self::assertSame(['FINANCE_AR_NOT_FOUND', 'Scan run not found'], [$error['code'], $error['message']]);
    }

    public function test_with_mailbox_reading_off_a_scan_is_refused_in_a_typed_way_and_leaves_a_failed_run_not_a_held_claim(): void
    {
        $first = $this->call('POST', '/scan', [Module::PERMISSION_AR_RECORDS_WRITE], []);
        $second = $this->call('POST', '/scan', [Module::PERMISSION_AR_RECORDS_WRITE], []);

        foreach ([$first, $second] as $response) {
            self::assertContains($response->getStatusCode(), [403, 502], 'never a 200, never a 500');
            self::assertContains(self::errorOf($response)['code'], ['FINANCE_AR_FORBIDDEN', 'FINANCE_AR_UPSTREAM_FAILED']);
        }
        self::assertSame($first->getStatusCode(), $second->getStatusCode(), 'the second scan is not blocked by the first: the failed run released the mailbox');
        $runs = TestAppFactory::json($this->call('GET', '/scan-runs', [Module::PERMISSION_AR_RECORDS_READ]));
        $data = $runs['data'];
        self::assertIsArray($data);
        $rows = ArFixtures::rows($data['runs']);
        self::assertCount(2, $rows);
        self::assertSame(['failed', 'failed'], array_column($rows, 'status'));
    }

    public function test_the_daily_job_is_registered_only_with_the_app_and_mailbox_reading_both_on(): void
    {
        $on = ['FINANCE_MODULE_ENABLED' => 'true', 'FINANCE_AR_ENABLED' => 'true'];

        self::assertNotContains(ArMailboxScanJob::NAME, $this->jobNames($on), 'reading off');
        self::assertNotContains(ArMailboxScanJob::NAME, $this->jobNames($on + ['FINANCE_MAILBOX_READ_ENABLED' => 'true']), 'reading on but the Graph settings missing counts as off');
        self::assertContains(ArMailboxScanJob::NAME, $this->jobNames($on + ['FINANCE_MAILBOX_READ_ENABLED' => 'true'] + self::GRAPH));
        self::assertNotContains(ArMailboxScanJob::NAME, $this->jobNames(['FINANCE_MODULE_ENABLED' => 'true', 'FINANCE_AR_ENABLED' => 'false', 'FINANCE_MAILBOX_READ_ENABLED' => 'true'] + self::GRAPH), 'app off');
        self::assertNotContains(ArMailboxScanJob::NAME, $this->jobNames(['FINANCE_MODULE_ENABLED' => 'false', 'FINANCE_AR_ENABLED' => 'true', 'FINANCE_MAILBOX_READ_ENABLED' => 'true'] + self::GRAPH), 'module off');
    }

    public function test_with_the_ar_switch_off_the_scan_routes_do_not_exist(): void
    {
        $this->app = TestAppFactory::create(['FINANCE_MODULE_ENABLED' => 'true', 'FINANCE_AR_ENABLED' => 'false']);

        self::assertSame(404, $this->call('POST', '/scan', [Module::PERMISSION_AR_RECORDS_WRITE], [])->getStatusCode());
        self::assertSame(404, $this->call('GET', '/scan-runs', [Module::PERMISSION_AR_RECORDS_READ])->getStatusCode());
    }
}
