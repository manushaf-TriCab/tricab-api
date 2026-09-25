<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Slim\App;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Identity\Domain\Service\PermissionAssurancePolicy;
use TriCab\Core\Identity\Domain\ValueObject\AuthenticationAssurance;
use TriCab\Core\Identity\Domain\ValueObject\PermissionCode;
use TriCab\Core\Onboarding\Application\DTO\CompletionFeed\CompletionFeedEntry;
use TriCab\Core\Onboarding\Contract\OnboardingAdminAuditLog;
use TriCab\Core\Onboarding\Contract\OnboardingCompletionFeedRepository;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingAdminAuditLog;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingCompletionFeedRepository;

/**
 * Phase 6 over HTTP — the completion feed's authorization and wire contract
 * (work order §7, exit: "contract, test fixtures and API authorization tests
 * exist").
 *
 * Held here: only `ONBOARDING.COMPLETIONS.READ` opens it — no HR permission and
 * no self-service one — and an emailed code is never enough; the page matches
 * the v1 fixture exactly; and no key outside the contract's allow-list can
 * appear, so a score, an answer or an address cannot leak in by accident.
 */
final class OnboardingCompletionFeedEndpointsTest extends TestCase
{
    private const FEED = '/api/v1/integrations/onboarding/completions';
    private const FIXTURE = __DIR__ . '/../Fixtures/Onboarding/completion-feed-v1.json';

    public function test_only_the_feed_permission_opens_it(): void
    {
        foreach ([
            ['ONBOARDING.SELF.READ', 'ONBOARDING.SELF.SUBMIT', 'ONBOARDING.ACCESS'],
            ['ONBOARDING.REVIEW'],
            ['ONBOARDING.PEOPLE.MANAGE'],
            ['ONBOARDING.CONTENT.MANAGE'],
            ['ONBOARDING.REPORTS.READ'],
            ['ONBOARDING.ADMIN'],
        ] as $held) {
            $response = $this->app(['account.login', ...$held])->handle(TestAppFactory::request('GET', self::FEED));
            self::assertSame(403, $response->getStatusCode(), implode(',', $held) . ' opened the feed');
        }

        $response = $this->app()->handle(TestAppFactory::request('GET', self::FEED));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    }

    public function test_an_emailed_code_is_never_enough(): void
    {
        $permission = PermissionCode::fromString('ONBOARDING.COMPLETIONS.READ');

        self::assertFalse(PermissionAssurancePolicy::isSatisfiedBy($permission, AuthenticationAssurance::low()));
        self::assertTrue(PermissionAssurancePolicy::isSatisfiedBy($permission, AuthenticationAssurance::high()));
    }

    public function test_the_page_is_the_v1_fixture_and_the_read_is_audited(): void
    {
        $app = $this->app();
        $feed = $this->get($app, OnboardingCompletionFeedRepository::class, InMemoryOnboardingCompletionFeedRepository::class);
        $feed->with(new CompletionFeedEntry(41, 'eeeeeeee-0000-4000-8000-000000000041', CompletionFeedEntry::TYPE_RECORDED, '2026-09-24T01:15:00Z', 'aaaaaaaa-0000-4000-8000-000000000007', 'training', 'bbbbbbbb-0000-4000-8000-000000000001', 'corporate-whse-induction', 'Corporate and WHSE Induction', 1, 'cccccccc-0000-4000-8000-000000000041'));
        $feed->with(new CompletionFeedEntry(57, 'eeeeeeee-0000-4000-8000-000000000057', CompletionFeedEntry::TYPE_REOPENED, '2026-09-24T03:40:00Z', 'aaaaaaaa-0000-4000-8000-000000000007', 'policy', 'bbbbbbbb-0000-4000-8000-000000000002', 'hr-pol-036-code-of-conduct', 'Code of Conduct', 2, 'cccccccc-0000-4000-8000-000000000057'));

        $response = $app->handle(TestAppFactory::request('GET', self::FEED . '?cursor=0&limit=50'));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $payload = TestAppFactory::json($response);
        $fixture = json_decode((string) file_get_contents(self::FIXTURE), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($fixture, $payload['data'] ?? null);
        $audit = $this->get($app, OnboardingAdminAuditLog::class, InMemoryOnboardingAdminAuditLog::class);
        self::assertCount(1, $audit->entries);
    }

    public function test_no_key_outside_the_contract_can_appear(): void
    {
        $fixture = json_decode((string) file_get_contents(self::FIXTURE), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($fixture);

        self::assertSame(['schemaVersion', 'items', 'nextCursor', 'hasMore'], array_keys($fixture));
        foreach (is_array($fixture['items']) ? $fixture['items'] : [] as $item) {
            self::assertIsArray($item);
            self::assertSame(['eventId', 'cursor', 'type', 'occurredAtUtc', 'person', 'item', 'evidenceReference'], array_keys($item));
            self::assertSame(['userPublicId'], array_keys(is_array($item['person']) ? $item['person'] : []));
            self::assertSame(['type', 'publicId', 'slug', 'title', 'versionNumber'], array_keys(is_array($item['item']) ? $item['item'] : []));
            self::assertIsString($item['evidenceReference']);
            self::assertStringStartsNotWith('http', $item['evidenceReference'], 'a reference, never a URL');
        }
    }

    public function test_a_malformed_cursor_is_a_validation_failure(): void
    {
        $response = $this->app()->handle(TestAppFactory::request('GET', self::FEED . '?cursor=not-a-cursor'));

        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
    }

    /**
     * @param list<string> $permissions
     *
     * @return App<ContainerInterface>
     */
    private function app(array $permissions = ['account.login', 'ONBOARDING.COMPLETIONS.READ']): App
    {
        $app = TestAppFactory::create();
        $users = $this->get($app, UserRepository::class, InMemoryApiUserRepository::class);
        $users->replacePermissions(1, $permissions, ['EMPLOYEE']);

        return $app;
    }

    /**
     * @template T of object
     *
     * @param App<ContainerInterface> $app
     * @param class-string<T>         $class
     *
     * @return T
     */
    private function get(App $app, string $id, string $class): object
    {
        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $value = $container->get($id);
        self::assertInstanceOf($class, $value);

        return $value;
    }
}
