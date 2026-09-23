<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Onboarding\Contract\OnboardingReportRepository;
use TriCab\Core\Onboarding\Domain\ValueObject\AssignmentStatus;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingReportRepository;

/**
 * Phase 4.5 over HTTP: that `ONBOARDING.REPORTS.READ` opens the three reports
 * and no neighbouring permission does, and what a report reader is — and is
 * not — sent.
 */
final class OnboardingReportEndpointsTest extends TestCase
{
    private const BASE = '/api/v1/admin/onboarding/reports/';

    public function test_reports_need_reports_read_and_nothing_else_opens_them(): void
    {
        foreach ([['ONBOARDING.SELF.READ', 'ONBOARDING.SELF.SUBMIT'], ['ONBOARDING.PEOPLE.MANAGE'], ['ONBOARDING.CONTENT.MANAGE'], ['ONBOARDING.REVIEW'], ['ONBOARDING.ADMIN']] as $held) {
            $app = $this->app(['account.login', ...$held]);
            foreach (['summary', 'items', 'overdue'] as $report) {
                $response = $app->handle(TestAppFactory::request('GET', self::BASE . $report));
                self::assertSame(403, $response->getStatusCode(), implode(',', $held) . ' reached ' . $report);
            }
        }

        $app = $this->app();
        foreach (['summary', 'items', 'overdue'] as $report) {
            self::assertSame(200, $app->handle(TestAppFactory::request('GET', self::BASE . $report))->getStatusCode(), $report);
        }
    }

    public function test_a_report_name_the_route_table_does_not_list_is_not_found(): void
    {
        $response = $this->app()->handle(TestAppFactory::request('GET', self::BASE . 'everything'));

        self::assertSame(404, $response->getStatusCode());
    }

    public function test_the_overdue_list_is_paginated_and_names_people_without_contact_details(): void
    {
        $app = $this->app();
        $this->withRoster($app);

        $data = $this->data($app->handle(TestAppFactory::request('GET', self::BASE . 'overdue?pageSize=1')));

        self::assertSame(['page' => 1, 'pageSize' => 1, 'totalItems' => 2, 'totalPages' => 2], $data['pagination']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', self::str($data['asOf']));
        $rows = self::rows($data['items'] ?? null);
        self::assertCount(1, $rows);
        self::assertSame('Person One', $rows[0]['displayName']);
        self::assertSame('Overdue policy', $rows[0]['title']);

        $body = (string) $app->handle(TestAppFactory::request('GET', self::BASE . 'overdue'))->getBody();
        foreach (['email', 'userId', 'score', 'note', 'phone', 'mobile'] as $key) {
            self::assertStringNotContainsStringIgnoringCase('"' . $key, $body, $key);
        }
    }

    public function test_the_item_report_counts_and_the_summary_matches_it(): void
    {
        $app = $this->app();
        $this->withRoster($app);

        $items = self::rows($this->data($app->handle(TestAppFactory::request('GET', self::BASE . 'items')))['items'] ?? null);
        self::assertSame([['policy', 2, 0, 2]], array_map(static fn (array $r) => [$r['itemType'], $r['assigned'], $r['completed'], $r['overdue']], $items));

        $summary = $this->data($app->handle(TestAppFactory::request('GET', self::BASE . 'summary')));
        self::assertIsArray($summary['work']);
        self::assertSame(2, $summary['work']['overdue']);
    }

    // --------------------------------------------------------------- helpers

    /**
     * @param App<ContainerInterface> $app
     */
    private function withRoster(App $app): void
    {
        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $reports = $container->get(OnboardingReportRepository::class);
        self::assertInstanceOf(InMemoryOnboardingReportRepository::class, $reports);

        $reports->withItem('policy', 'p1', 'Overdue policy');
        foreach ([7 => 'Person One', 8 => 'Person Two'] as $userId => $name) {
            $reports->withPerson($userId, 'person-' . $userId, $name);
            // Due well in the past whatever day the suite runs.
            $reports->withAssignment('as-' . $userId, $userId, 'policy', 'p1', AssignmentStatus::inProgress(), '2020-01-0' . ($userId - 6));
        }
    }

    /**
     * @param list<string> $permissions
     *
     * @return App<ContainerInterface>
     */
    private function app(array $permissions = ['account.login', 'ONBOARDING.REPORTS.READ']): App
    {
        $app = TestAppFactory::create();
        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $users = $container->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $users);
        $users->replacePermissions(1, $permissions, ['EMPLOYEE']);

        return $app;
    }

    /**
     * @return array<string, mixed>
     */
    private function data(ResponseInterface $response): array
    {
        $payload = TestAppFactory::json($response);
        self::assertIsArray($payload['data'] ?? null, (string) $response->getBody());

        /** @var array<string, mixed> */
        return $payload['data'];
    }

    private static function str(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function rows(mixed $value): array
    {
        self::assertIsArray($value);
        $rows = [];
        foreach ($value as $row) {
            self::assertIsArray($row);
            /** @var array<string, mixed> $row */
            $rows[] = $row;
        }

        return $rows;
    }
}
