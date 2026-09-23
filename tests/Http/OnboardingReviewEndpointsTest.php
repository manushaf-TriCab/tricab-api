<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Onboarding\Application\DTO\Admin\ReviewItemRecord;
use TriCab\Core\Onboarding\Application\DTO\Admin\ReviewQueueEntry;
use TriCab\Core\Onboarding\Contract\OnboardingAdminAuditLog;
use TriCab\Core\Onboarding\Contract\OnboardingReviewRepository;
use TriCab\Core\Onboarding\Domain\ValueObject\AssignmentStatus;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingAdminAuditLog;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingReviewRepository;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;

/**
 * Phase 4.3 over HTTP: the queue, one item, and a decision.
 *
 * What only this layer can show: that `ONBOARDING.REVIEW` opens these routes
 * and the neighbouring HR permissions do not (work order §9, "reviewer and
 * content-manager scopes remain separate"), and what each refusal looks like
 * on the wire. The signed-in test user is user 1.
 */
final class OnboardingReviewEndpointsTest extends TestCase
{
    private const QUEUE = '/api/v1/admin/onboarding/queue';
    private const ITEM = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const OWN = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';

    private RowVersionToken $token;

    protected function setUp(): void
    {
        $this->token = RowVersionToken::fromBinary("\x00\x00\x00\x00\x00\x00\x0F\xA0");
    }

    public function test_the_queue_needs_review_and_nothing_else_opens_it(): void
    {
        foreach ([['ONBOARDING.SELF.READ', 'ONBOARDING.SELF.SUBMIT'], ['ONBOARDING.PEOPLE.MANAGE'], ['ONBOARDING.CONTENT.MANAGE'], ['ONBOARDING.REPORTS.READ'], ['ONBOARDING.ADMIN']] as $held) {
            $app = $this->app(['account.login', ...$held]);

            foreach ([
                TestAppFactory::request('GET', self::QUEUE),
                TestAppFactory::request('GET', self::QUEUE . '/' . self::ITEM),
                $this->decision(self::ITEM, ['rowVersion' => $this->token->toBase64(), 'outcome' => 'completed']),
            ] as $request) {
                $response = $app->handle($request);
                self::assertSame(403, $response->getStatusCode(), implode(',', $held) . ' reached ' . $request->getMethod() . ' ' . $request->getUri()->getPath());
            }
        }
    }

    public function test_the_queue_is_paginated_and_says_which_items_are_the_viewers_own(): void
    {
        $app = $this->app();
        $this->withItems($app);

        $response = $app->handle(TestAppFactory::request('GET', self::QUEUE . '?pageSize=1'));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $data = $this->data($response);

        self::assertSame(['page' => 1, 'pageSize' => 1, 'totalItems' => 2, 'totalPages' => 2], $data['pagination']);
        $items = self::rows($data['items'] ?? null);
        self::assertCount(1, $items);
        self::assertArrayNotHasKey('subjectUserId', $items[0]);
        self::assertArrayNotHasKey('emailAddress', $items[0]);

        $all = self::rows($this->data($app->handle(TestAppFactory::request('GET', self::QUEUE)))['items'] ?? null);
        self::assertSame([self::ITEM => false, self::OWN => true], array_column($all, 'isOwn', 'assignmentPublicId'));
    }

    public function test_an_item_offers_the_decisions_the_api_will_accept(): void
    {
        $app = $this->app();
        $this->withItems($app);

        $data = $this->data($app->handle(TestAppFactory::request('GET', self::QUEUE . '/' . self::ITEM)));
        self::assertSame(
            [['status' => 'completed', 'label' => 'Complete', 'requiresNote' => false], ['status' => 'fix_and_resubmit', 'label' => 'Changes requested', 'requiresNote' => true]],
            $data['outcomes'],
        );
        self::assertSame($this->token->toBase64(), $data['rowVersion']);

        $withheld = self::rows(is_array($data['formSubmission'] ?? null) ? $data['formSubmission']['fields'] : null);
        self::assertSame([['path' => 'bank.accountNumber', 'label' => 'Account number', 'value' => null, 'withheld' => true]], $withheld);

        $own = $this->data($app->handle(TestAppFactory::request('GET', self::QUEUE . '/' . self::OWN)));
        self::assertSame([], $own['outcomes']);
    }

    public function test_an_unknown_or_malformed_item_is_not_found(): void
    {
        $app = $this->app();

        foreach (['bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'not-a-uuid'] as $id) {
            $response = $app->handle(TestAppFactory::request('GET', self::QUEUE . '/' . $id));
            self::assertSame(404, $response->getStatusCode());
            self::assertSame('ONBOARDING_NOT_FOUND', $this->code($response));
        }
    }

    public function test_a_decision_moves_the_item_and_returns_it_decided(): void
    {
        $app = $this->app();
        $this->withItems($app);

        $response = $app->handle($this->decision(self::ITEM, [
            'rowVersion' => $this->token->toBase64(),
            'outcome' => 'fix_and_resubmit',
            'note' => 'Your start date is before the offer date.',
        ]));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $data = $this->data($response);
        self::assertIsArray($data['item']);
        self::assertSame('fix_and_resubmit', $data['item']['status']);
        self::assertSame([], $data['outcomes'], 'decided, so nothing more is offered');
        self::assertNotSame($this->token->toBase64(), $data['rowVersion']);
        self::assertSame('Your start date is before the offer date.', self::rows($data['history'] ?? null)[0]['note']);
    }

    public function test_each_refusal_has_its_own_code(): void
    {
        $app = $this->app();
        $this->withItems($app);
        $body = ['rowVersion' => $this->token->toBase64(), 'outcome' => 'completed'];

        $own = $app->handle($this->decision(self::OWN, $body));
        self::assertSame(403, $own->getStatusCode());
        self::assertSame('ONBOARDING_SELF_REVIEW_NOT_PERMITTED', $this->code($own));

        $notOffered = $app->handle($this->decision(self::ITEM, [...$body, 'outcome' => 'in_progress', 'note' => 'x']));
        self::assertSame(409, $notOffered->getStatusCode());
        self::assertSame('ONBOARDING_REVIEW_OUTCOME_NOT_OFFERED', $this->code($notOffered));

        $stale = $app->handle($this->decision(self::ITEM, [...$body, 'rowVersion' => RowVersionToken::fromBinary("\x00\x00\x00\x00\x00\x00\x00\x02")->toBase64()]));
        self::assertSame(409, $stale->getStatusCode());
        self::assertSame('ONBOARDING_STALE_TOKEN', $this->code($stale));

        self::assertSame(200, $app->handle($this->decision(self::ITEM, $body))->getStatusCode());
        $again = $app->handle($this->decision(self::ITEM, $body));
        self::assertSame(409, $again->getStatusCode());
        self::assertSame('ONBOARDING_REVIEW_NOT_AWAITING_REVIEW', $this->code($again));

        $audit = $this->container($app)->get(OnboardingAdminAuditLog::class);
        self::assertInstanceOf(InMemoryOnboardingAdminAuditLog::class, $audit);
        self::assertSame(
            ['self_review', 'outcome_not_offered', 'not_awaiting_review'],
            array_map(static fn (array $e) => $e['event']->details['reason'] ?? null, $audit->entries),
            'every refusal is on record; the stale save is a conflict, not a decision refused',
        );
    }

    public function test_a_malformed_decision_is_a_validation_failure(): void
    {
        $app = $this->app();
        $this->withItems($app);

        foreach ([
            ['outcome' => 'completed'],
            ['rowVersion' => 'not base64!', 'outcome' => 'completed'],
            ['rowVersion' => $this->token->toBase64()],
            ['rowVersion' => $this->token->toBase64(), 'outcome' => 'definitely_done'],
            ['rowVersion' => $this->token->toBase64(), 'outcome' => 'fix_and_resubmit'],
            ['rowVersion' => $this->token->toBase64(), 'outcome' => 'fix_and_resubmit', 'note' => ['not', 'text']],
        ] as $body) {
            $response = $app->handle($this->decision(self::ITEM, $body));
            self::assertSame(422, $response->getStatusCode(), json_encode($body, JSON_THROW_ON_ERROR) . ' ' . $response->getBody());
            self::assertSame('VALIDATION_FAILED', $this->code($response));
        }
    }

    // --------------------------------------------------------------- helpers

    /**
     * @param App<ContainerInterface> $app
     */
    private function withItems(App $app): void
    {
        $reviews = $this->container($app)->get(OnboardingReviewRepository::class);
        self::assertInstanceOf(InMemoryOnboardingReviewRepository::class, $reviews);

        foreach ([[self::ITEM, 7, '2026-09-01T00:00:00Z'], [self::OWN, 1, '2026-09-02T00:00:00Z']] as [$id, $subject, $since]) {
            $status = AssignmentStatus::submitted();
            $reviews->withItem(
                new ReviewItemRecord(1, $id, $subject, 'form', 'form-item', 'Starter details', $status, $this->token, 1),
                new ReviewQueueEntry($id, $subject, 'person-' . $subject, 'Person ' . $subject, 'form', 'form-item', 'Starter details', 1, $status, $since, null, 1),
                [
                    'revisionNumber' => 1,
                    'submittedAtUtc' => $since,
                    'hasRestrictedData' => true,
                    'fields' => [['path' => 'bank.accountNumber', 'label' => 'Account number', 'value' => null, 'withheld' => true]],
                ],
            );
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private function decision(string $id, array $body): ServerRequestInterface
    {
        $request = TestAppFactory::request('POST', self::QUEUE . '/' . $id . '/decision')
            ->withHeader('Content-Type', 'application/json');
        $request->getBody()->write(json_encode($body, JSON_THROW_ON_ERROR));

        return $request;
    }

    /**
     * @param list<string> $permissions
     *
     * @return App<ContainerInterface>
     */
    private function app(array $permissions = ['account.login', 'ONBOARDING.REVIEW']): App
    {
        $app = TestAppFactory::create();
        $users = $this->container($app)->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $users);
        $users->replacePermissions(1, $permissions, ['EMPLOYEE']);

        return $app;
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function container(App $app): ContainerInterface
    {
        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);

        return $container;
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
