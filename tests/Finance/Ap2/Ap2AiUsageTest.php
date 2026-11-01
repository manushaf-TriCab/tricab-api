<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap2;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Slim\App;
use TriCab\Api\Finance\Ap2\Ai\ErrorLogAp2AiUsageLog;
use TriCab\Api\Finance\Ap2\Ai\InMemoryAp2AiUsageLog;
use TriCab\Api\Finance\Ap2\AzureSqlAp2AiUsageLog;
use TriCab\Api\Finance\Ap2\InMemoryAp2InvoiceStore;
use TriCab\Api\Finance\Ap2\InMemoryAp2PaymentStore;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\Doubles\FakeSqlConnection;
use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Finance\Ap2\Service\Ap2Time;
use TriCab\Core\Finance\Ap2\Ai\Ap2AiUsage;
use TriCab\Core\Finance\Ap2\Contract\Ap2AiUsageReport;
use TriCab\Core\Finance\Ap2\Domain\Ap2Invalid;
use TriCab\Core\Finance\Ap2\Service\Ap2AiUsageService;
use TriCab\Core\Finance\Module;
use TriCab\Core\Identity\Contract\UserRepository;

/** The AI usage log reading side: the in-memory totals, the service period rules, the route, and a SQL log that never throws. */
final class Ap2AiUsageTest extends TestCase
{
    private static function usage(string $purpose, string $model, float $cost, bool $ok = true, int $calls = 1): Ap2AiUsage
    {
        return new Ap2AiUsage($purpose, $model, 'p-v1', 1000, 100, 10, 5, $cost, 800, $calls, $ok);
    }

    private static function at(string $iso): DateTimeImmutable
    {
        return new DateTimeImmutable($iso, new DateTimeZone('UTC'));
    }

    public function testInMemoryTotalsGroupByPurposeAndModelWithinThePeriod(): void
    {
        $log = new InMemoryAp2AiUsageLog();
        $log->recordAt(self::usage(Ap2AiUsage::PURPOSE_EXTRACT, 'm1', 0.01), self::at('2026-10-10T01:00:00'));
        $log->recordAt(self::usage(Ap2AiUsage::PURPOSE_EXTRACT, 'm1', 0.02, false), self::at('2026-10-11T01:00:00'));
        $log->recordAt(self::usage(Ap2AiUsage::PURPOSE_CLASSIFY, 'm2', 0.001, true, 2), self::at('2026-10-11T02:00:00'));
        $log->recordAt(self::usage(Ap2AiUsage::PURPOSE_CLASSIFY, 'm2', 5.0), self::at('2026-10-12T00:00:00'));

        $totals = $log->totals(self::at('2026-10-10T00:00:00'), self::at('2026-10-12T00:00:00'));

        self::assertCount(2, $totals);
        self::assertSame(Ap2AiUsage::PURPOSE_EXTRACT, $totals[0]->purpose);
        self::assertSame(2, $totals[0]->calls);
        self::assertSame(1, $totals[0]->failed);
        self::assertSame(2000, $totals[0]->inputTokens);
        self::assertSame(20, $totals[0]->cacheReadTokens);
        self::assertSame(0.03, $totals[0]->costUsd);
        self::assertSame(1600, $totals[0]->durationMs);
        self::assertSame(Ap2AiUsage::PURPOSE_CLASSIFY, $totals[1]->purpose);
        self::assertSame(2, $totals[1]->calls);
        self::assertSame(0.001, $totals[1]->costUsd);
    }

    private function service(InMemoryAp2AiUsageLog $log): Ap2AiUsageService
    {
        $clock = new FixedClock(new DateTimeImmutable('2026-10-27T03:00:00+00:00'));

        return new Ap2AiUsageService($log, new InMemoryAp2InvoiceStore(new InMemoryAp2PaymentStore()), new Ap2Time($clock, 'Australia/Sydney'));
    }

    public function testTheServiceDefaultsToTheLast30DaysAndPricesPerInvoice(): void
    {
        $log = new InMemoryAp2AiUsageLog();
        $log->recordAt(self::usage(Ap2AiUsage::PURPOSE_COPILOT, 'm1', 0.5), self::at('2026-10-20T01:00:00'));
        $report = $this->service($log)->usage([]);

        self::assertSame('2026-10-27', $report['to']);
        self::assertSame('2026-09-28', $report['from']);
        self::assertSame(0.5, Ap2Json::get($report, 'totals.costUsd'));
        self::assertSame(1, Ap2Json::get($report, 'totals.calls'));
        self::assertSame(0, $report['invoices']);
        self::assertNull($report['costPerInvoiceUsd']);
        self::assertSame(['ap2_copilot' => ['calls' => 1, 'costUsd' => 0.5]], (array) $report['byPurpose']);
        self::assertSame('m1', Ap2Json::get($report, 'rows.0.model'));
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function badPeriods(): iterable
    {
        yield 'not a date' => [['from' => 'yesterday']];
        yield 'impossible date' => [['to' => '2026-02-30']];
        yield 'from after to' => [['from' => '2026-10-20', 'to' => '2026-10-19']];
        yield 'too long' => [['from' => '2025-10-01', 'to' => '2026-10-27']];
    }

    /** @param array<string, string> $query */
    #[DataProvider('badPeriods')]
    public function testTheServiceRefusesABadPeriod(array $query): void
    {
        $this->expectException(Ap2Invalid::class);
        $this->service(new InMemoryAp2AiUsageLog())->usage($query);
    }

    public function testTheServiceAcceptsTheLongestPeriodAndASingleDay(): void
    {
        $report = $this->service(new InMemoryAp2AiUsageLog())->usage(['from' => '2025-10-27', 'to' => '2026-10-27']);
        self::assertSame('2025-10-27', $report['from']);
        $same = $this->service(new InMemoryAp2AiUsageLog())->usage(['from' => '2026-10-27', 'to' => '2026-10-27']);
        self::assertSame($same['from'], $same['to']);
    }

    public function testTheSqlLogSwallowsADatabaseErrorAndInsertsOtherwise(): void
    {
        $connection = new FakeSqlConnection();
        $log = new AzureSqlAp2AiUsageLog($connection, new ErrorLogAp2AiUsageLog());
        $previous = ini_set('error_log', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ap2-ai-usage-test.log');
        try {
            $log->record(self::usage(Ap2AiUsage::PURPOSE_EXTRACT, 'm1', 0.012345));
            self::assertCount(1, $connection->calls);
            self::assertStringContainsString('[finance].[ap2_ai_usage]', $connection->calls[0]['sql']);
            self::assertSame('0.012345', $connection->calls[0]['params']['cost']);
            self::assertSame(1, $connection->calls[0]['params']['ok']);

            $connection->failNextWrite = new RuntimeException('database is down');
            $log->record(self::usage(Ap2AiUsage::PURPOSE_EXTRACT, 'm1', 0.01, false));
            self::assertCount(2, $connection->calls);
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
        }
    }

    public function testTheSqlTotalsMapRowsAndBindTheBounds(): void
    {
        $connection = new FakeSqlConnection([[
            'purpose' => 'ap2_invoice_extract', 'model' => 'm1', 'calls' => 3, 'failed' => 1, 'input_tokens' => 3000,
            'output_tokens' => 300, 'cache_read_tokens' => 0, 'cache_write_tokens' => 5, 'cost_usd' => '0.030000', 'duration_ms' => 2400,
        ]]);
        $log = new AzureSqlAp2AiUsageLog($connection, new ErrorLogAp2AiUsageLog());

        $totals = $log->totals(self::at('2026-10-01T00:00:00'), self::at('2026-10-02T00:00:00'));

        self::assertCount(1, $totals);
        self::assertSame(3, $totals[0]->calls);
        self::assertSame(0.03, $totals[0]->costUsd);
        self::assertSame(2400, $totals[0]->durationMs);
        self::assertArrayHasKey('from', $connection->calls[0]['params']);
        self::assertArrayHasKey('to', $connection->calls[0]['params']);
    }

    /** @return App<ContainerInterface> */
    private function app(): App
    {
        return TestAppFactory::create(['FINANCE_MODULE_ENABLED' => 'true', 'FINANCE_AP2_ENABLED' => 'true']);
    }

    /**
     * @param App<ContainerInterface> $app
     * @param list<string> $permissions
     */
    private function grant(App $app, array $permissions): void
    {
        $users = $app->getContainer()->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $users);
        $users->replacePermissions(1, ['account.login', Module::PERMISSION_ACCESS, ...$permissions], ['EMPLOYEE']);
    }

    public function testTheRouteReturnsTheReportAndRefusesABadPeriod(): void
    {
        $app = $this->app();
        $this->grant($app, [Module::PERMISSION_AP2_ACCESS, ...Module::AP2_PERMISSIONS]);
        $report = $app->getContainer()->get(Ap2AiUsageReport::class);
        self::assertInstanceOf(InMemoryAp2AiUsageLog::class, $report);
        $report->record(self::usage(Ap2AiUsage::PURPOSE_CLASSIFY, 'm1', 0.25));

        $from = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-1 day')->format('Y-m-d');
        $to = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d');
        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/finance/ap2/ai-usage?from=' . $from . '&to=' . $to));
        $json = TestAppFactory::json($response);
        self::assertSame(200, $response->getStatusCode());
        $data = $json['data'];
        self::assertIsArray($data);
        self::assertSame($from, $data['from']);
        self::assertSame(0.25, Ap2Json::get($data, 'totals.costUsd'));
        self::assertSame(1, Ap2Json::get($data, 'rows.0.calls'));
        self::assertSame(0, $data['invoices']);
        self::assertNull($data['costPerInvoiceUsd']);

        $bad = $app->handle(TestAppFactory::request('GET', '/api/v1/finance/ap2/ai-usage?from=2026-10-20&to=2026-10-01'));
        self::assertSame(422, $bad->getStatusCode());
        self::assertSame('FINANCE_AP2_INPUT_INVALID', Ap2Json::get(TestAppFactory::json($bad), 'error.code'));
    }

    public function testTheRouteNeedsTheSettingsPermission(): void
    {
        $app = $this->app();
        $this->grant($app, [Module::PERMISSION_AP2_ACCESS, Module::PERMISSION_AP2_INVOICES_READ]);

        self::assertSame(403, $app->handle(TestAppFactory::request('GET', '/api/v1/finance/ap2/ai-usage'))->getStatusCode());
    }
}
