<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Onboarding\Email\DevelopmentOnboardingInvitationEmailSender;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Onboarding\Contract\OnboardingAccountDirectory;
use TriCab\Core\Onboarding\Contract\OnboardingInvitationEmailSender;
use TriCab\Core\Onboarding\Contract\OnboardingPeopleRepository;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingAccountDirectory;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingPeopleRepository;

/**
 * Phase 4.1 over HTTP: add a person, edit their profile, invite them.
 *
 * What only this layer shows: that `ONBOARDING.PEOPLE.MANAGE` opens these
 * routes and the review permission does not, that each refusal has its own
 * code, and that nothing on the wire carries a sign-in code.
 */
final class OnboardingPersonLifecycleEndpointsTest extends TestCase
{
    private const PEOPLE = '/api/v1/admin/onboarding/people';

    public function test_only_people_manage_opens_the_routes(): void
    {
        foreach ([['ONBOARDING.REVIEW'], ['ONBOARDING.CONTENT.MANAGE'], ['ONBOARDING.SELF.READ', 'ONBOARDING.SELF.SUBMIT'], ['ONBOARDING.ADMIN']] as $held) {
            $app = $this->app(['account.login', ...$held]);
            $id = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

            foreach ([
                $this->json('POST', self::PEOPLE, ['emailAddress' => 'x@example.test', 'displayName' => 'X']),
                $this->json('PUT', self::PEOPLE . '/' . $id . '/profile', ['rowVersion' => 'AAAAAAAAAAE=']),
                $this->json('POST', self::PEOPLE . '/' . $id . '/invitation', []),
                $this->json('DELETE', self::PEOPLE . '/' . $id, ['rowVersion' => 'AAAAAAAAAAE=']),
            ] as $request) {
                self::assertSame(403, $app->handle($request)->getStatusCode(), implode(',', $held) . ' reached ' . $request->getUri()->getPath());
            }
        }
    }

    public function test_a_person_is_added_edited_and_invited(): void
    {
        $app = $this->app();
        // The in-memory directory numbers new accounts from 5000; tell the
        // in-memory roster what the Azure one would read from identity.Users.
        $people = $this->container($app)->get(OnboardingPeopleRepository::class);
        self::assertInstanceOf(InMemoryOnboardingPeopleRepository::class, $people);
        $people->withAccount(5000, 'New Starter', 'new.starter@example.test');

        $created = $app->handle($this->json('POST', self::PEOPLE, [
            'emailAddress' => 'new.starter@example.test',
            'displayName' => 'New Starter',
            'position' => 'Storeperson',
            'startDate' => '2026-10-06',
        ]));
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $data = $this->data($created);
        self::assertSame(['accountCreated' => true], $data['result']);
        self::assertTrue($data['canSignIn']);
        self::assertIsArray($data['person']);
        $publicId = $data['person']['publicId'];
        self::assertIsString($publicId);
        self::assertIsString($data['rowVersion']);

        $edited = $app->handle($this->json('PUT', self::PEOPLE . '/' . $publicId . '/profile', [
            'rowVersion' => $data['rowVersion'],
            'position' => 'Driver',
            'startDate' => '2026-10-06',
            'lastDay' => '2027-01-29',
        ]));
        self::assertSame(200, $edited->getStatusCode(), (string) $edited->getBody());
        $person = $this->data($edited)['person'];
        self::assertIsArray($person);
        self::assertSame('Driver', $person['position']);
        self::assertSame('2027-01-29', $person['lastDay']);

        $invited = $app->handle($this->json('POST', self::PEOPLE . '/' . $publicId . '/invitation', []));
        self::assertSame(200, $invited->getStatusCode(), (string) $invited->getBody());
        $invitedPerson = $this->data($invited)['person'];
        self::assertIsArray($invitedPerson);
        self::assertNotNull($invitedPerson['invitedAtUtc']);
        self::assertDoesNotMatchRegularExpression('/\b\d{6}\b/', (string) $invited->getBody(), 'no code-shaped value on the wire');

        $sender = $this->container($app)->get(OnboardingInvitationEmailSender::class);
        self::assertInstanceOf(DevelopmentOnboardingInvitationEmailSender::class, $sender);
        $message = $sender->lastMessage();
        self::assertNotNull($message);
        self::assertSame('new.starter@example.test', $message['to']);
        self::assertStringEndsWith('/onboarding', $message['onboardingUrl']);

        $again = $app->handle($this->json('POST', self::PEOPLE . '/' . $publicId . '/invitation', []));
        self::assertSame(409, $again->getStatusCode());
        self::assertSame('ONBOARDING_INVITATION_TOO_SOON', $this->code($again));

        $stale = $app->handle($this->json('PUT', self::PEOPLE . '/' . $publicId . '/profile', ['rowVersion' => $data['rowVersion'], 'position' => 'Late']));
        self::assertSame(409, $stale->getStatusCode());
        self::assertSame('ONBOARDING_STALE_TOKEN', $this->code($stale));
    }

    public function test_a_person_added_by_mistake_is_deleted_and_one_with_recorded_work_is_refused(): void
    {
        $app = $this->app();
        $people = $this->container($app)->get(OnboardingPeopleRepository::class);
        self::assertInstanceOf(InMemoryOnboardingPeopleRepository::class, $people);
        $people->withAccount(5000, 'Added Twice', 'twice@example.test');

        $data = $this->data($app->handle($this->json('POST', self::PEOPLE, ['emailAddress' => 'twice@example.test', 'displayName' => 'Added Twice'])));
        self::assertIsArray($data['person']);
        $publicId = $data['person']['publicId'];
        self::assertIsString($publicId);

        $read = $this->data($app->handle(TestAppFactory::request('GET', self::PEOPLE . '/' . $publicId)));
        self::assertTrue($read['canDelete']);
        self::assertSame([], $read['recordedWork']);

        $people->withRecordedWork(5000, ['progress' => 1]);
        $refused = $app->handle($this->json('DELETE', self::PEOPLE . '/' . $publicId, ['rowVersion' => $read['rowVersion']]));
        self::assertSame(409, $refused->getStatusCode());
        self::assertSame('ONBOARDING_PERSON_HAS_RECORDED_WORK', $this->code($refused));

        $people->withRecordedWork(5000, []);
        $deleted = $app->handle($this->json('DELETE', self::PEOPLE . '/' . $publicId, ['rowVersion' => $read['rowVersion']]));
        self::assertSame(200, $deleted->getStatusCode(), (string) $deleted->getBody());
        self::assertSame(['deleted' => true, 'accountDisabled' => true], $this->data($deleted));

        self::assertSame(404, $app->handle(TestAppFactory::request('GET', self::PEOPLE . '/' . $publicId))->getStatusCode());

        $noToken = $app->handle($this->json('DELETE', self::PEOPLE . '/' . $publicId, []));
        self::assertSame(422, $noToken->getStatusCode(), 'the token is required');
    }

    public function test_adding_somebody_twice_and_inviting_somebody_who_cannot_sign_in_are_refused_by_name(): void
    {
        $app = $this->app();
        $directory = $this->container($app)->get(OnboardingAccountDirectory::class);
        self::assertInstanceOf(InMemoryOnboardingAccountDirectory::class, $directory);
        $directory->withAccount(321, 'pending@tricab.com', false);

        $first = $app->handle($this->json('POST', self::PEOPLE, ['emailAddress' => 'pending@tricab.com']));
        self::assertSame(201, $first->getStatusCode(), (string) $first->getBody());
        self::assertSame(['accountCreated' => false], $this->data($first)['result']);
        self::assertFalse($this->data($first)['canSignIn']);

        $second = $app->handle($this->json('POST', self::PEOPLE, ['emailAddress' => 'pending@tricab.com']));
        self::assertSame(409, $second->getStatusCode());
        self::assertSame('ONBOARDING_PERSON_EXISTS', $this->code($second));

        $person = $this->data($first)['person'];
        self::assertIsArray($person);
        self::assertIsString($person['publicId']);
        $invite = $app->handle($this->json('POST', self::PEOPLE . '/' . $person['publicId'] . '/invitation', []));
        self::assertSame(409, $invite->getStatusCode());
        self::assertSame('ONBOARDING_INVITATION_CANNOT_SIGN_IN', $this->code($invite));
    }

    public function test_malformed_input_is_a_validation_failure(): void
    {
        $app = $this->app();

        foreach ([
            ['emailAddress' => 'not an address', 'displayName' => 'X'],
            ['emailAddress' => 'new@example.test'],
            ['emailAddress' => 'new@example.test', 'displayName' => 'X', 'startDate' => '6/10/2026'],
            ['emailAddress' => ['x'], 'displayName' => 'X'],
        ] as $body) {
            $response = $app->handle($this->json('POST', self::PEOPLE, $body));
            self::assertSame(422, $response->getStatusCode(), json_encode($body, JSON_THROW_ON_ERROR) . ' ' . $response->getBody());
            self::assertSame('VALIDATION_FAILED', $this->code($response));
        }
    }

    // --------------------------------------------------------------- helpers

    /**
     * @param array<string, mixed> $body
     */
    private function json(string $method, string $path, array $body): ServerRequestInterface
    {
        $request = TestAppFactory::request($method, $path)->withHeader('Content-Type', 'application/json');
        $request->getBody()->write(json_encode((object) $body, JSON_THROW_ON_ERROR));

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
}
