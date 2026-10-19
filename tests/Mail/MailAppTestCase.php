<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Mail;

use DI\Container;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Api\Tests\Payload;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Mail\Application\Service\MailIntakeRecorder;
use TriCab\Core\Mail\Contract\MailStore;
use TriCab\Core\Mail\Module;
use TriCab\Core\Shared\Time\Clock;

/** The real app with the inbox switched on, a signed-in person holding the permissions a test names, and mail to look at. */
abstract class MailAppTestCase extends TestCase
{
    /** @var App<ContainerInterface> */
    protected App $app;

    protected MailIntakeRecorder $recorder;

    /**
     * @param list<string> $permissions
     * @param array<string, string> $env
     */
    protected function boot(array $permissions, array $env = []): void
    {
        $this->app = TestAppFactory::create($env + ['MAIL_MODULE_ENABLED' => 'true']);
        $container = $this->container();
        // After the fixtures' mail, so a person's action sorts after the reader's and the classifier's lines.
        $container->set(Clock::class, FixedClock::utc('2026-10-19 09:00:00'));
        $users = $container->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $users);
        $users->replacePermissions(1, ['account.login', ...$permissions], ['EMPLOYEE']);
        $store = $container->get(MailStore::class);
        self::assertInstanceOf(MailStore::class, $store);
        $this->recorder = new MailIntakeRecorder($store);
    }

    protected function container(): Container
    {
        $container = $this->app->getContainer();
        self::assertInstanceOf(Container::class, $container);

        return $container;
    }

    /** A nested read of a decoded response: each step is asserted to be there. */
    protected static function at(mixed $value, string|int ...$keys): mixed
    {
        return Payload::at($value, ...$keys);
    }

    /** @return list<string> */
    protected static function reviewer(): array
    {
        return [Module::PERMISSION_ACCESS, Module::PERMISSION_REVIEW];
    }

    /** @param array<string, mixed>|null $body */
    protected function call(string $method, string $path, ?array $body = null): ResponseInterface
    {
        $request = TestAppFactory::request($method, $path);
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }

        return $this->app->handle($request);
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{0: int, 1: array<string, mixed>}
     */
    protected function json(string $method, string $path, ?array $body = null): array
    {
        $response = $this->call($method, $path, $body);

        return [$response->getStatusCode(), TestAppFactory::json($response)];
    }

    /** @return array<string, mixed> */
    protected function data(string $path): array
    {
        [$status, $json] = $this->json('GET', $path);
        self::assertSame(200, $status, json_encode($json, JSON_THROW_ON_ERROR));
        self::assertTrue($json['success']);
        return Payload::obj($json['data']);
    }
}
