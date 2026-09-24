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
use TriCab\Core\Onboarding\Application\DTO\Contribution\FormContributionContext;
use TriCab\Core\Onboarding\Contract\OnboardingFormContributionRepository;
use TriCab\Core\Onboarding\Domain\ValueObject\AssignmentStatus;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingFormContributionRepository;

/**
 * Phase 5.4 over HTTP: HR's and the manager's parts of a form. The signed-in
 * test user is user 1.
 */
final class OnboardingContributionEndpointsTest extends TestCase
{
    private const FORM = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    private const SCHEMA = ['fields' => [
        ['path' => 'siteTour', 'label' => 'Tour of site done', 'type' => 'checkbox', 'required' => true, 'filledBy' => 'hr'],
        ['path' => 'managerSignOff', 'label' => 'Manager sign-off', 'type' => 'checkbox', 'required' => true, 'filledBy' => 'manager'],
    ]];

    public function test_hrs_part_needs_review_and_self_service_does_not_open_it(): void
    {
        $app = $this->app(['account.login', 'ONBOARDING.SELF.READ', 'ONBOARDING.SELF.SUBMIT']);
        $this->withForm($app, managerUserId: 1);

        self::assertSame(403, $app->handle(TestAppFactory::request('GET', $this->hr()))->getStatusCode());
        self::assertSame(403, $app->handle($this->put($this->hr(), ['siteTour' => true]))->getStatusCode());
    }

    public function test_hr_fills_its_part_and_reads_it_back(): void
    {
        $app = $this->app(['account.login', 'ONBOARDING.REVIEW']);
        $this->withForm($app, managerUserId: 5);

        $response = $app->handle($this->put($this->hr(), ['siteTour' => true]));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $data = $this->data($response);
        self::assertSame('hr', $data['role']);
        self::assertSame(['hr' => ['siteTour' => true]], $data['contributions']);
    }

    public function test_hr_writing_the_manager_part_is_a_validation_error(): void
    {
        $app = $this->app(['account.login', 'ONBOARDING.REVIEW']);
        $this->withForm($app, managerUserId: 5);

        self::assertSame(422, $app->handle($this->put($this->hr(), ['managerSignOff' => true]))->getStatusCode());
    }

    public function test_the_named_manager_lists_and_fills_the_manager_part(): void
    {
        $app = $this->app(['account.login', 'ONBOARDING.SELF.READ']);
        $this->withForm($app, managerUserId: 1);

        $list = $this->data($app->handle(TestAppFactory::request('GET', '/api/v1/onboarding/team/forms')));
        self::assertSame([self::FORM], array_column(is_array($list['items']) ? $list['items'] : [], 'assignmentPublicId'));

        $response = $app->handle($this->put('/api/v1/onboarding/team/forms/' . self::FORM . '/contribution', ['managerSignOff' => true]));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(['manager' => ['managerSignOff' => true]], $this->data($response)['contributions']);
    }

    public function test_anyone_but_the_named_manager_gets_not_found(): void
    {
        $app = $this->app(['account.login', 'ONBOARDING.SELF.READ']);
        $this->withForm($app, managerUserId: 5);

        self::assertSame([], $this->data($app->handle(TestAppFactory::request('GET', '/api/v1/onboarding/team/forms')))['items']);
        self::assertSame(404, $app->handle(TestAppFactory::request('GET', '/api/v1/onboarding/team/forms/' . self::FORM))->getStatusCode());
        self::assertSame(404, $app->handle($this->put('/api/v1/onboarding/team/forms/' . self::FORM . '/contribution', ['managerSignOff' => true]))->getStatusCode());
    }

    private function hr(): string
    {
        return '/api/v1/admin/onboarding/queue/' . self::FORM . '/contribution';
    }

    /**
     * @param array<string, mixed> $answers
     */
    private function put(string $path, array $answers): ServerRequestInterface
    {
        $request = TestAppFactory::request('PUT', $path)->withHeader('Content-Type', 'application/json');
        $request->getBody()->write(json_encode(['answers' => $answers], JSON_THROW_ON_ERROR));

        return $request;
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function withForm(App $app, int $managerUserId): void
    {
        $repository = $this->container($app)->get(OnboardingFormContributionRepository::class);
        self::assertInstanceOf(InMemoryOnboardingFormContributionRepository::class, $repository);
        $repository->withForm(new FormContributionContext(9, self::FORM, 7, $managerUserId, 'Induction checklist', 'hr', AssignmentStatus::inProgress(), self::SCHEMA));
    }

    /**
     * @param list<string> $permissions
     * @return App<ContainerInterface>
     */
    private function app(array $permissions): App
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
}
