<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use TriCab\Api\Hr\Employees\Directory\EmployeeDirectory;
use TriCab\Api\Hr\Employees\Directory\HrPermissions;
use TriCab\Api\Hr\Employees\Directory\InMemoryEmployeeDirectory;
use TriCab\Api\Hr\OnboardingLink\InMemoryOnboardingLinkRepository;
use TriCab\Api\Hr\OnboardingLink\OnboardingLinkProposer;
use TriCab\Api\Hr\OnboardingLink\OnboardingLinkRepository;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\Hr\Employees\Directory\EmployeeDirectoryFixtures;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Onboarding\Application\DTO\Admin\OnboardingPersonRecord;
use TriCab\Core\Onboarding\Application\DTO\Admin\OnboardingPersonSummary;
use TriCab\Core\Onboarding\Contract\OnboardingPeopleRepository;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingPeopleRepository;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;

/** The onboarding-to-employee link over HTTP: who may use it, what it returns, what it writes. */
final class HrOnboardingLinkEndpointsTest extends TestCase
{
    private const PERSON = 'aaaaaaaa-0000-4000-8000-000000000001';
    private const BASE = '/api/v1/hr/onboarding-links/people/' . self::PERSON;
    private const READ = HrPermissions::EMPLOYEES_READ;
    private const MANAGE = 'ONBOARDING.PEOPLE.MANAGE';

    public function test_each_route_needs_both_the_directory_and_the_onboarding_permission(): void
    {
        $routes = [
            ['GET', self::BASE],
            ['POST', self::BASE . '/proposals/00000000-0000-4000-8000-000000000001/confirm'],
            ['POST', self::BASE . '/proposals/00000000-0000-4000-8000-000000000001/dismiss'],
            ['DELETE', self::BASE],
        ];
        foreach ([[self::READ], [self::MANAGE], [HrPermissions::EMPLOYEES_RESTRICTED_READ, self::MANAGE]] as $held) {
            $app = $this->app($held);
            foreach ($routes as [$method, $path]) {
                self::assertSame(403, $app->handle(TestAppFactory::request($method, $path))->getStatusCode(), implode(',', $held) . " $method $path");
            }
        }
        self::assertSame(200, $this->app([self::READ, self::MANAGE])->handle(TestAppFactory::request('GET', self::BASE))->getStatusCode());
    }

    public function test_the_person_shows_the_proposed_match_and_confirming_it_links_and_unlinking_clears_it(): void
    {
        $app = $this->app([self::READ, self::MANAGE]);

        $view = $this->data($app->handle(TestAppFactory::request('GET', self::BASE)));
        self::assertNull($view['linked']);
        $proposals = self::rows($view['proposals'] ?? null);
        self::assertCount(1, $proposals);
        self::assertSame('login', $proposals[0]['reason']);
        $proposalId = self::str($proposals[0]['publicId'] ?? null);

        $confirmed = $this->data($app->handle(TestAppFactory::request('POST', self::BASE . '/proposals/' . $proposalId . '/confirm')));
        $linked = self::assoc($confirmed['linked'] ?? null);
        $employee = self::assoc($linked['employee'] ?? null);
        self::assertSame('E002', $employee['code']);
        self::assertSame('2015-03-02', $employee['inPayrollSince']);
        self::assertStringNotContainsString('1980-02-03', json_encode($confirmed, JSON_THROW_ON_ERROR));

        $again = $app->handle(TestAppFactory::request('POST', self::BASE . '/proposals/' . $proposalId . '/confirm'));
        self::assertSame(409, $again->getStatusCode());
        self::assertSame('HR_LINK_PERSON_ALREADY_LINKED', $this->code($again));

        $cleared = $this->data($app->handle(TestAppFactory::request('DELETE', self::BASE)));
        self::assertNull($cleared['linked']);
        $second = $app->handle(TestAppFactory::request('DELETE', self::BASE));
        self::assertSame('HR_LINK_NOT_LINKED', $this->code($second));
    }

    public function test_unknown_person_and_unknown_proposal_are_404(): void
    {
        $app = $this->app([self::READ, self::MANAGE]);

        self::assertSame(404, $app->handle(TestAppFactory::request('GET', '/api/v1/hr/onboarding-links/people/00000000-0000-4000-8000-00000000ffff'))->getStatusCode());
        $missing = $app->handle(TestAppFactory::request('POST', self::BASE . '/proposals/00000000-0000-4000-8000-00000000ffff/dismiss'));
        self::assertSame(404, $missing->getStatusCode());
        self::assertSame('HR_LINK_PROPOSAL_NOT_FOUND', $this->code($missing));
    }

    public function test_the_employee_detail_carries_the_onboarding_record_only_for_those_who_may_see_it(): void
    {
        $app = $this->app([self::READ, self::MANAGE]);
        $proposalId = self::str(self::rows($this->data($app->handle(TestAppFactory::request('GET', self::BASE)))['proposals'] ?? null)[0]['publicId'] ?? null);

        $before = $this->data($app->handle(TestAppFactory::request('GET', '/api/v1/hr/employees/E002')));
        self::assertArrayHasKey('onboarding', $before);
        self::assertNull($before['onboarding']);

        $app->handle(TestAppFactory::request('POST', self::BASE . '/proposals/' . $proposalId . '/confirm'));
        $after = $this->data($app->handle(TestAppFactory::request('GET', '/api/v1/hr/employees/E002')));
        $onboarding = $after['onboarding'];
        self::assertIsArray($onboarding);
        self::assertSame(self::PERSON, $onboarding['personPublicId']);
        self::assertSame('Ann Lee', $onboarding['displayName']);
        self::assertSame([2, 1], [$onboarding['assignedCount'], $onboarding['completedCount']]);
        self::assertIsInt($onboarding['progressPercent']);
        self::assertGreaterThan(0, $onboarding['progressPercent']);

        $directoryOnly = $this->data($this->appWithoutReseed([self::READ], $app)->handle(TestAppFactory::request('GET', '/api/v1/hr/employees/E002')));
        self::assertArrayNotHasKey('onboarding', $directoryOnly);
    }

    /**
     * @param list<string> $permissions
     *
     * @return App<ContainerInterface>
     */
    private function app(array $permissions): App
    {
        $app = TestAppFactory::create();
        $container = $this->container($app);
        $users = $container->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $users);
        $users->replacePermissions(1, ['account.login', ...$permissions], ['EMPLOYEE']);

        $directory = $container->get(EmployeeDirectory::class);
        self::assertInstanceOf(InMemoryEmployeeDirectory::class, $directory);
        EmployeeDirectoryFixtures::seed($directory);

        // Ann Lee (platform user 101) is the E002 payroll row's user; the person starts with her login linked.
        $directory->withEmployee(EmployeeDirectoryFixtures::employee('E002', 'ANN', 'Ann', 'Lee', 'OpsExtru', 'MELB', 'Ceo', '2015-03-02') + ['linkedUser' => null]);
        $links = $container->get(OnboardingLinkRepository::class);
        self::assertInstanceOf(InMemoryOnboardingLinkRepository::class, $links);
        $links->withPerson(self::PERSON, 1, 101, 'Ann Lee', '2026-11-02')->withEmployee('E002', 101, 'Ann Lee', 'Ann', 'Lee', '2015-03-02');
        (new OnboardingLinkProposer($links))->propose();

        $people = $container->get(OnboardingPeopleRepository::class);
        self::assertInstanceOf(InMemoryOnboardingPeopleRepository::class, $people);
        $people->withPerson(
            new OnboardingPersonRecord(101, self::PERSON, RowVersionToken::fromBinary("\x00\x00\x00\x00\x00\x00\x07\xD1"), null),
            new OnboardingPersonSummary(self::PERSON, 'Ann Lee', 'ann@example.test', 'Operator', null, null, '2026-11-02', null, null, ['completed' => 1, 'not_started' => 1]),
        );

        return $app;
    }

    /**
     * @param list<string> $permissions
     * @param App<ContainerInterface> $app
     *
     * @return App<ContainerInterface>
     */
    private function appWithoutReseed(array $permissions, App $app): App
    {
        $users = $this->container($app)->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $users);
        $users->replacePermissions(1, ['account.login', ...$permissions], ['EMPLOYEE']);

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
        $error = TestAppFactory::json($response)['error'] ?? null;
        self::assertIsArray($error);
        $code = $error['code'] ?? null;
        self::assertIsString($code);

        return $code;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function rows(mixed $value): array
    {
        self::assertIsArray($value);

        /** @var list<array<string, mixed>> */
        return array_values($value);
    }

    /**
     * @return array<string, mixed>
     */
    private static function assoc(mixed $value): array
    {
        self::assertIsArray($value);

        /** @var array<string, mixed> */
        return $value;
    }

    private static function str(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }
}
