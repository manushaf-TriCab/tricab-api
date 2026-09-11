<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use TriCab\Api\Expense\Persistence\InMemoryExpenseProfileRepository;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Expense\Contract\ExpenseProfileRepository;
use TriCab\Core\Expense\Domain\Entity\ExpenseProfile;
use TriCab\Core\Expense\Module;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Identity\Domain\Entity\ExternalIdentity;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\ExternalProvider;
use TriCab\Core\Identity\Domain\ValueObject\ExternalSubjectId;
use TriCab\Core\Identity\Domain\ValueObject\ExternalTenantId;
use TriCab\Core\Identity\Domain\ValueObject\PermissionCode;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;

final class TriExpenseFinanceEndpointsTest extends TestCase
{
    public function test_finance_import_match_period_export_and_te_admin_denied(): void
    {
        $app = $this->appWithWorld();
        $world = $this->worldUsers($app);

        $csv = "Bank Reference,Cardholder Email,First Name,Last Name,Transaction Date,Posting Date,Statement Date,Merchant Name,Merchant City,Merchant Country,Merchant Postcode,Amount,Currency,Foreign Amount,Foreign Currency,Net Amount,Tax Amount,Transaction Type,Merchant Category Group\n"
            . "HTTP-1,thilanh@tricab.com,Thilan,H,2026-09-10,2026-09-10,2026-09-30,Cafe,Melbourne,AU,3000,25.00,AUD,,,,,,,\n";

        $teAdminDenied = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/tri-expense/finance/bank-feeds/import',
            ['country' => 'AU', 'csvContents' => $csv],
            $world['teAdmin'],
        ));
        self::assertSame(403, $teAdminDenied->getStatusCode());
        $deniedPayload = TestAppFactory::json($teAdminDenied);
        self::assertSame('TRIEXPENSE_ACCESS_DENIED', $deniedPayload['error']['code']);

        $import = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/tri-expense/finance/bank-feeds/import',
            ['country' => 'AU', 'csvContents' => $csv],
            $world['finance'],
        ));
        $importPayload = TestAppFactory::json($import);
        self::assertSame(200, $import->getStatusCode(), (string) $import->getBody());
        self::assertSame(1, $importPayload['data']['imported']);

        $match = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/tri-expense/finance/bank-feeds/match',
            ['country' => 'AU'],
            $world['finance'],
        ));
        self::assertSame(200, $match->getStatusCode());
        $matchPayload = TestAppFactory::json($match);
        self::assertArrayHasKey('matched', $matchPayload['data']);
        self::assertArrayHasKey('nearMisses', $matchPayload['data']);

        $period = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/tri-expense/finance/periods/get-or-create',
            ['country' => 'AU'],
            $world['finance'],
        ));
        self::assertSame(200, $period->getStatusCode(), (string) $period->getBody());
        $periodPayload = TestAppFactory::json($period);
        self::assertSame('AU', $periodPayload['data']['country']);
        self::assertSame('open', $periodPayload['data']['status']);

        $export = $app->handle(
            TestAppFactory::request('GET', '/api/v1/tri-expense/finance/export/reconciliation?country=AU')
                ->withHeader('X-Dev-Entra-Subject', $world['finance']['subject'])
                ->withHeader('X-Dev-Entra-Email', $world['finance']['email']),
        );
        self::assertSame(200, $export->getStatusCode(), (string) $export->getBody());
        self::assertStringContainsString('text/csv', $export->getHeaderLine('Content-Type'));
        $csvBody = (string) $export->getBody();
        self::assertStringStartsWith('Transaction Date,Posting Date,Cardholder,', $csvBody);
        self::assertStringContainsString('HTTP-1', $csvBody);
    }

    public function test_finance_config_flags_enabled(): void
    {
        $app = $this->appWithWorld();
        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/tri-expense/config'));
        $payload = TestAppFactory::json($response);
        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($payload['data']['features']['bankReconciliation']);
        self::assertTrue($payload['data']['features']['periodClose']);
    }

    private function appWithWorld(): App
    {
        $app = TestAppFactory::create();
        $users = $this->users($app);
        $manager = $this->registerTeUser($users, 'mgr@tricab.com', 'development-te-mgr');
        $finance = $this->registerTeUser($users, 'finance@tricab.com', 'development-te-finance');
        $teAdmin = $this->registerTeUser($users, 'teadmin@tricab.com', 'development-te-admin');

        $this->seedProfile($app, $manager->id()->toInt(), 'mgr', 'mgr@tricab.com');
        $this->seedProfile($app, $finance->id()->toInt(), 'finance', 'finance@tricab.com', finance: true, managerUserId: $manager->id()->toInt());
        $this->seedProfile($app, $teAdmin->id()->toInt(), 'teadmin', 'teadmin@tricab.com', admin: true, managerUserId: $manager->id()->toInt());
        $this->seedProfile($app, 1, 'thilan', 'thilanh@tricab.com', managerUserId: $manager->id()->toInt());

        return $app;
    }

    /**
     * @return array<string, array{userId:int,email:string,subject:string}>
     */
    private function worldUsers(App $app): array
    {
        $users = $this->users($app);
        $finance = $users->findByEmail(EmailAddress::fromString('finance@tricab.com'));
        $teAdmin = $users->findByEmail(EmailAddress::fromString('teadmin@tricab.com'));
        self::assertNotNull($finance);
        self::assertNotNull($teAdmin);

        return [
            'finance' => ['userId' => $finance->id()->toInt(), 'email' => 'finance@tricab.com', 'subject' => 'development-te-finance'],
            'teAdmin' => ['userId' => $teAdmin->id()->toInt(), 'email' => 'teadmin@tricab.com', 'subject' => 'development-te-admin'],
        ];
    }

    private function registerTeUser(InMemoryApiUserRepository $users, string $email, string $subject): \TriCab\Core\Identity\Domain\Entity\User
    {
        return $users->register(
            EmailAddress::fromString($email),
            $email,
            UserStatus::active(),
            [RoleCode::fromString('EMPLOYEE')],
            [PermissionCode::fromString('ACCOUNT.LOGIN'), PermissionCode::fromString('TRIEXPENSE.ACCESS')],
            new ExternalIdentity(
                ExternalProvider::fromString(ExternalProvider::MICROSOFT_ENTRA),
                ExternalSubjectId::fromString($subject),
                ExternalTenantId::fromString('development-tenant'),
            ),
        );
    }

    private function seedProfile(
        App $app,
        int $userId,
        string $login,
        string $email,
        bool $active = true,
        bool $finance = false,
        bool $admin = false,
        ?int $managerUserId = null,
    ): void {
        $profiles = $this->profiles($app);
        $profiles->save(new ExpenseProfile(
            UserId::fromInt($userId),
            $login,
            $email,
            $active,
            $finance,
            $admin,
            $managerUserId !== null ? 'mgr' : null,
            $managerUserId !== null ? UserId::fromInt($managerUserId) : null,
        ));
    }

    private function users(App $app): InMemoryApiUserRepository
    {
        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $repository = $container->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $repository);

        return $repository;
    }

    private function profiles(App $app): InMemoryExpenseProfileRepository
    {
        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $profiles = $container->get(ExpenseProfileRepository::class);
        self::assertInstanceOf(InMemoryExpenseProfileRepository::class, $profiles);

        return $profiles;
    }

    /**
     * @param array<string, mixed> $body
     * @param array{email?:string,subject?:string} $actor
     */
    private function jsonRequest(string $method, string $path, array $body, array $actor = []): ServerRequestInterface
    {
        $request = TestAppFactory::request($method, $path)
            ->withHeader('Content-Type', 'application/json');
        if (isset($actor['subject'])) {
            $request = $request->withHeader('X-Dev-Entra-Subject', (string) $actor['subject']);
        }
        if (isset($actor['email'])) {
            $request = $request->withHeader('X-Dev-Entra-Email', (string) $actor['email']);
        }
        $request->getBody()->write(json_encode($body, JSON_THROW_ON_ERROR));

        return $request;
    }
}
