<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Onboarding\Application\DTO\Admin\AllocatableItem;
use TriCab\Core\Onboarding\Application\DTO\Admin\CurrentAllocation;
use TriCab\Core\Onboarding\Application\DTO\Admin\OnboardingPersonRecord;
use TriCab\Core\Onboarding\Application\DTO\Admin\OnboardingPersonSummary;
use TriCab\Core\Onboarding\Contract\OnboardingAdminAuditLog;
use TriCab\Core\Onboarding\Contract\OnboardingPeopleRepository;
use TriCab\Core\Onboarding\Domain\ValueObject\AssignmentStatus;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingAdminAuditLog;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingPeopleRepository;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;

/**
 * Phase 4.2 over HTTP: the roster, one person, and the allocation save.
 *
 * What only this layer can show: which permission opens the routes (and that
 * the neighbouring HR permissions do not), what a refusal looks like on the
 * wire, and that a save reports the boxes it could not untick.
 */
final class OnboardingAdminPeopleEndpointsTest extends TestCase
{
    private const PEOPLE = '/api/v1/admin/onboarding/people';
    private const PERSON = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const INDUCTION = '11111111-1111-4111-8111-111111111111';
    private const SAFETY = '22222222-2222-4222-8222-222222222222';

    private RowVersionToken $token;

    protected function setUp(): void
    {
        $this->token = RowVersionToken::fromBinary("\x00\x00\x00\x00\x00\x00\x07\xD1");
    }

    public function test_the_roster_needs_people_manage_and_nothing_else_opens_it(): void
    {
        foreach ([['ONBOARDING.SELF.READ', 'ONBOARDING.SELF.SUBMIT'], ['ONBOARDING.REVIEW'], ['ONBOARDING.CONTENT.MANAGE'], ['ONBOARDING.REPORTS.READ']] as $held) {
            $app = $this->app(['account.login', ...$held]);

            foreach ([
                TestAppFactory::request('GET', self::PEOPLE),
                TestAppFactory::request('GET', self::PEOPLE . '/' . self::PERSON),
                $this->put(['rowVersion' => $this->token->toBase64(), 'items' => []]),
            ] as $request) {
                $response = $app->handle($request);
                self::assertSame(403, $response->getStatusCode(), implode(',', $held) . ' reached ' . $request->getUri()->getPath());
            }
        }
    }

    public function test_the_roster_is_paginated_and_searchable(): void
    {
        $app = $this->app();
        $people = $this->people($app);
        foreach (['Ari', 'Bea', 'Cai'] as $index => $name) {
            $id = sprintf('0000000%d-0000-4000-8000-000000000000', $index);
            $people->withPerson(
                new OnboardingPersonRecord(10 + $index, $id, $this->token, null),
                new OnboardingPersonSummary($id, $name, strtolower($name) . '@example.test', null, null, null, null, null, null, ['completed' => 1, 'submitted' => 1]),
            );
        }

        $page = $this->data($app->handle(TestAppFactory::request('GET', self::PEOPLE . '?page=2&pageSize=2')));
        $items = self::rows($page['items'] ?? null);
        self::assertSame(['Cai'], array_column($items, 'displayName'));
        self::assertSame(['page' => 2, 'pageSize' => 2, 'totalItems' => 3, 'totalPages' => 2], $page['pagination']);
        self::assertSame(1, $items[0]['awaitingReviewCount']);
        self::assertSame(88, $items[0]['progressPercent']);

        $found = $this->data($app->handle(TestAppFactory::request('GET', self::PEOPLE . '?search=bea')));
        self::assertSame(['Bea'], array_column(self::rows($found['items'] ?? null), 'displayName'));
    }

    public function test_an_unknown_or_malformed_person_is_not_found(): void
    {
        $app = $this->app();

        foreach (['bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'not-a-uuid'] as $id) {
            $response = $app->handle(TestAppFactory::request('GET', self::PEOPLE . '/' . $id));
            self::assertSame(404, $response->getStatusCode());
            self::assertSame('ONBOARDING_NOT_FOUND', $this->code($response));
        }
    }

    public function test_a_save_allocates_withdraws_and_reports_what_it_kept(): void
    {
        $app = $this->app();
        $this->withPerson($app, null, [
            new CurrentAllocation(5, 'held-started', 'policy', self::SAFETY, 'Site safety', 4, AssignmentStatus::inProgress(), null, true, '2026-09-01T00:00:00Z'),
        ]);

        $response = $app->handle($this->put([
            'rowVersion' => $this->token->toBase64(),
            'items' => [['itemType' => 'training', 'itemPublicId' => self::INDUCTION, 'dueDate' => '2026-10-01']],
        ]));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $data = $this->data($response);
        $result = $data['result'] ?? null;
        self::assertIsArray($result);
        self::assertSame(1, $result['allocatedCount']);
        self::assertSame(0, $result['withdrawnCount']);
        self::assertSame([[
            'assignmentPublicId' => 'held-started',
            'itemType' => 'policy',
            'title' => 'Site safety',
            'status' => 'in_progress',
        ]], $result['kept']);
        self::assertNotSame($this->token->toBase64(), $data['rowVersion']);

        $allocated = array_values(array_filter(self::rows($data['allocations'] ?? null), static fn (array $a) => $a['itemType'] === 'training'));
        self::assertSame('2026-10-01', $allocated[0]['dueDate']);
    }

    public function test_a_stale_token_is_a_conflict(): void
    {
        $app = $this->app();
        $this->withPerson($app, null, []);
        $body = ['rowVersion' => $this->token->toBase64(), 'items' => [['itemType' => 'training', 'itemPublicId' => self::INDUCTION]]];

        self::assertSame(200, $app->handle($this->put($body))->getStatusCode());

        $second = $app->handle($this->put([...$body, 'items' => []]));
        self::assertSame(409, $second->getStatusCode());
        self::assertSame('ONBOARDING_STALE_TOKEN', $this->code($second));
    }

    public function test_a_leaver_is_refused_by_reason_and_the_refusal_is_audited(): void
    {
        $app = $this->app();
        $this->withPerson($app, new DateTimeImmutable('2026-01-01T00:00:00', new DateTimeZone('UTC')), []);

        $response = $app->handle($this->put([
            'rowVersion' => $this->token->toBase64(),
            'items' => [['itemType' => 'policy', 'itemPublicId' => self::SAFETY]],
        ]));

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('ONBOARDING_ALLOCATION_DEPARTED', $this->code($response));

        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $audit = $container->get(OnboardingAdminAuditLog::class);
        self::assertInstanceOf(InMemoryOnboardingAdminAuditLog::class, $audit);
        self::assertCount(1, $audit->entries);
        self::assertSame('refused', $audit->entries[0]['event']->outcome);
    }

    public function test_a_malformed_body_is_a_validation_failure(): void
    {
        $app = $this->app();
        $this->withPerson($app, null, []);

        foreach ([
            ['items' => []],
            ['rowVersion' => 'not base64!', 'items' => []],
            ['rowVersion' => $this->token->toBase64()],
            ['rowVersion' => $this->token->toBase64(), 'items' => [['itemType' => 'cohort', 'itemPublicId' => self::SAFETY]]],
            ['rowVersion' => $this->token->toBase64(), 'items' => [['itemType' => 'policy', 'itemPublicId' => self::SAFETY, 'dueDate' => '31/10/2026']]],
        ] as $body) {
            $response = $app->handle($this->put($body));
            self::assertSame(422, $response->getStatusCode(), json_encode($body, JSON_THROW_ON_ERROR));
            self::assertSame('VALIDATION_FAILED', $this->code($response));
        }
    }

    // --------------------------------------------------------------- helpers

    /**
     * @param App<ContainerInterface> $app
     * @param list<CurrentAllocation> $allocations
     */
    private function withPerson(App $app, ?DateTimeImmutable $departedAtUtc, array $allocations): void
    {
        $people = $this->people($app);
        $people->withCatalogue([
            new AllocatableItem('training', self::INDUCTION, 'Site induction', 1, 'v-ind', 2),
            new AllocatableItem('policy', self::SAFETY, 'Site safety', 2, 'v-saf', 4),
        ]);
        $people->withPerson(
            new OnboardingPersonRecord(7, self::PERSON, $this->token, $departedAtUtc),
            new OnboardingPersonSummary(self::PERSON, 'Sam Example', 'sam@example.test', null, null, null, null, null, null, []),
        );
        $people->withAllocations(7, $allocations);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function put(array $body): ServerRequestInterface
    {
        $request = TestAppFactory::request('PUT', self::PEOPLE . '/' . self::PERSON . '/allocation')
            ->withHeader('Content-Type', 'application/json');
        $request->getBody()->write(json_encode($body, JSON_THROW_ON_ERROR));

        return $request;
    }

    /**
     * @param list<string> $permissions
     *
     * @return App<ContainerInterface>
     */
    private function app(array $permissions = ['account.login', 'ONBOARDING.PEOPLE.MANAGE']): App
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
     * @param App<ContainerInterface> $app
     */
    private function people(App $app): InMemoryOnboardingPeopleRepository
    {
        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $people = $container->get(OnboardingPeopleRepository::class);
        self::assertInstanceOf(InMemoryOnboardingPeopleRepository::class, $people);

        return $people;
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

    private function code(ResponseInterface $response): string
    {
        $payload = TestAppFactory::json($response);
        $error = $payload['error'] ?? null;
        self::assertIsArray($error, (string) $response->getBody());

        $code = $error['code'] ?? '';
        self::assertIsString($code);

        return $code;
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
