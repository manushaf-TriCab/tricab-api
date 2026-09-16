<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use TriCab\Api\Expense\Persistence\InMemoryExpenseProfileRepository;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Product\Storage\InMemoryProductBlobStore;
use TriCab\Api\Tests\Payload;
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
use TriCab\Core\Product\Contract\ProductBlobStore;

final class TriExpenseEndpointsTest extends TestCase
{
    public function test_tri_expense_routes_require_authentication(): void
    {
        $app = TestAppFactory::create(['DEV_AUTH_ENABLED' => 'false']);
        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/tri-expense/receipts'));
        $payload = TestAppFactory::json($response);
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('UNAUTHENTICATED', $payload['error']['code']);
    }

    public function test_tri_expense_routes_require_permission(): void
    {
        $app = TestAppFactory::create();
        $this->replacePermissions($app, ['ACCOUNT.LOGIN']);
        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/tri-expense/receipts'));
        $payload = TestAppFactory::json($response);
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('INSUFFICIENT_PERMISSION', Payload::obj($payload['error'])['code']);
    }

    public function test_inactive_expense_profile_is_denied(): void
    {
        $app = TestAppFactory::create();
        $this->seedProfile($app, 1, 'thilan', 'thilanh@tricab.com', active: false);
        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/tri-expense/receipts'));
        $payload = TestAppFactory::json($response);
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('TRIEXPENSE_ACCESS_DENIED', Payload::obj($payload['error'])['code']);
    }

    public function test_owner_lifecycle_row_version_and_self_approval(): void
    {
        $app = $this->appWithWorld();
        $created = $this->createDraft($app, [
            'vendorName' => 'Officeworks',
            'totalAmount' => '12.50',
            'gstType' => 'inclusive',
            'currency' => 'AUD',
            'category' => '64100',
            'receiptDate' => '2026-09-01',
        ]);
        self::assertSame(201, $created['status']);
        $receipt = Payload::obj($created['data']);
        self::assertSame('draft', $receipt['status']);
        self::assertSame('1.14', $receipt['gstAmount']);
        self::assertNotEmpty($receipt['rowVersion']);

        $getOwn = $app->handle(TestAppFactory::request('GET', '/api/v1/tri-expense/receipts/' . Payload::str($receipt['publicId'])));
        self::assertSame(200, $getOwn->getStatusCode());

        $patched = $app->handle($this->jsonRequest('PATCH', '/api/v1/tri-expense/receipts/' . Payload::str($receipt['publicId']), [
            'vendorName' => 'Officeworks Pty',
            'expectedRowVersion' => $receipt['rowVersion'],
        ]));
        $patchedPayload = TestAppFactory::json($patched);
        self::assertSame(200, $patched->getStatusCode(), (string) $patched->getBody());
        $receipt = Payload::obj($patchedPayload['data']);

        $stale = $app->handle($this->jsonRequest('PATCH', '/api/v1/tri-expense/receipts/' . Payload::str($receipt['publicId']), [
            'vendorName' => 'Stale',
            'expectedRowVersion' => $created['data']['rowVersion'],
        ]));
        $stalePayload = TestAppFactory::json($stale);
        self::assertSame(409, $stale->getStatusCode());
        self::assertSame('TRIEXPENSE_CONCURRENCY_CONFLICT', Payload::obj($stalePayload['error'])['code']);
        self::assertTrue(Payload::obj($stalePayload['error'])['reloadRequired']);

        $submitted = $app->handle($this->jsonRequest('POST', '/api/v1/tri-expense/receipts/' . Payload::str($receipt['publicId']) . '/submit', [
            'expectedRowVersion' => $receipt['rowVersion'],
        ]));
        $submittedPayload = TestAppFactory::json($submitted);
        self::assertSame(200, $submitted->getStatusCode(), (string) $submitted->getBody());
        $receipt = Payload::obj($submittedPayload['data']);
        self::assertSame('pending', $receipt['status']);

        $selfApprove = $app->handle($this->jsonRequest('POST', '/api/v1/tri-expense/receipts/' . Payload::str($receipt['publicId']) . '/approve', [
            'expectedRowVersion' => $receipt['rowVersion'],
        ]));
        $selfPayload = TestAppFactory::json($selfApprove);
        self::assertSame(403, $selfApprove->getStatusCode());
        self::assertSame('TRIEXPENSE_APPROVAL_FORBIDDEN', Payload::obj($selfPayload['error'])['code']);

        $world = $this->worldUsers($app);
        $strangerGet = $app->handle(
            TestAppFactory::request('GET', '/api/v1/tri-expense/receipts/' . Payload::str($receipt['publicId']))
                ->withHeader('X-Dev-Entra-Subject', $world['stranger']['subject'])
                ->withHeader('X-Dev-Entra-Email', $world['stranger']['email']),
        );
        self::assertSame(403, $strangerGet->getStatusCode());

        $managerGet = $app->handle(
            TestAppFactory::request('GET', '/api/v1/tri-expense/receipts/' . Payload::str($receipt['publicId']))
                ->withHeader('X-Dev-Entra-Subject', $world['manager']['subject'])
                ->withHeader('X-Dev-Entra-Email', $world['manager']['email']),
        );
        self::assertSame(200, $managerGet->getStatusCode());

        $financeGet = $app->handle(
            TestAppFactory::request('GET', '/api/v1/tri-expense/receipts/' . Payload::str($receipt['publicId']))
                ->withHeader('X-Dev-Entra-Subject', $world['finance']['subject'])
                ->withHeader('X-Dev-Entra-Email', $world['finance']['email']),
        );
        self::assertSame(200, $financeGet->getStatusCode());

        $unassignedApprove = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/tri-expense/receipts/' . Payload::str($receipt['publicId']) . '/approve',
            ['expectedRowVersion' => $receipt['rowVersion']],
            $world['stranger'],
        ));
        self::assertSame(403, $unassignedApprove->getStatusCode());

        $managerApprove = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/tri-expense/receipts/' . Payload::str($receipt['publicId']) . '/approve',
            ['expectedRowVersion' => $receipt['rowVersion']],
            $world['manager'],
        ));
        $approvedPayload = TestAppFactory::json($managerApprove);
        self::assertSame(200, $managerApprove->getStatusCode(), (string) $managerApprove->getBody());
        $receipt = Payload::obj($approvedPayload['data']);
        self::assertSame('approved', $receipt['status']);

        $managerSendBack = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/tri-expense/receipts/' . Payload::str($receipt['publicId']) . '/send-back',
            ['expectedRowVersion' => $receipt['rowVersion'], 'note' => 'no'],
            $world['manager'],
        ));
        self::assertSame(403, $managerSendBack->getStatusCode());

        $financeSendBack = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/tri-expense/receipts/' . Payload::str($receipt['publicId']) . '/send-back',
            ['expectedRowVersion' => $receipt['rowVersion'], 'note' => 'fix gst'],
            $world['finance'],
        ));
        self::assertSame(200, $financeSendBack->getStatusCode(), (string) $financeSendBack->getBody());
    }

    public function test_manager_assignment_stays_frozen_after_profile_change(): void
    {
        $app = $this->appWithWorld();
        $world = $this->worldUsers($app);
        $created = $this->createDraft($app);
        $this->seedProfile($app, 1, 'thilan', 'thilanh@tricab.com', managerUserId: $world['stranger']['userId']);

        $submitted = $app->handle($this->jsonRequest('POST', '/api/v1/tri-expense/receipts/' . Payload::str(Payload::obj($created['data'])['publicId']) . '/submit', [
            'expectedRowVersion' => $created['data']['rowVersion'],
        ]));
        self::assertSame(200, $submitted->getStatusCode(), (string) $submitted->getBody());

        $queue = $app->handle(
            TestAppFactory::request('GET', '/api/v1/tri-expense/approvals')
                ->withHeader('X-Dev-Entra-Subject', $world['manager']['subject'])
                ->withHeader('X-Dev-Entra-Email', $world['manager']['email']),
        );
        $queuePayload = TestAppFactory::json($queue);
        self::assertSame(200, $queue->getStatusCode());
        self::assertCount(1, Payload::list(Payload::obj($queuePayload['data'])['items']));

        $strangerQueue = $app->handle(
            TestAppFactory::request('GET', '/api/v1/tri-expense/approvals')
                ->withHeader('X-Dev-Entra-Subject', $world['stranger']['subject'])
                ->withHeader('X-Dev-Entra-Email', $world['stranger']['email']),
        );
        $strangerPayload = TestAppFactory::json($strangerQueue);
        self::assertCount(0, Payload::list(Payload::obj($strangerPayload['data'])['items']));
    }

    public function test_te_admin_without_finance_cannot_self_approve_but_can_approve_others(): void
    {
        $app = $this->appWithWorld();
        $world = $this->worldUsers($app);
        $own = $this->createDraft($app, headers: $world['teAdmin']);
        $submittedOwn = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/tri-expense/receipts/' . Payload::str(Payload::obj($own['data'])['publicId']) . '/submit',
            ['expectedRowVersion' => $own['data']['rowVersion']],
            $world['teAdmin'],
        ));
        $ownReceipt = Payload::obj(TestAppFactory::json($submittedOwn)['data']);
        $self = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/tri-expense/receipts/' . Payload::str($ownReceipt['publicId']) . '/approve',
            ['expectedRowVersion' => $ownReceipt['rowVersion']],
            $world['teAdmin'],
        ));
        self::assertSame(403, $self->getStatusCode());

        $other = $this->createDraft($app);
        $submittedOther = $app->handle($this->jsonRequest('POST', '/api/v1/tri-expense/receipts/' . Payload::str(Payload::obj($other['data'])['publicId']) . '/submit', [
            'expectedRowVersion' => $other['data']['rowVersion'],
        ]));
        $otherReceipt = Payload::obj(TestAppFactory::json($submittedOther)['data']);
        $adminApprove = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/tri-expense/receipts/' . Payload::str($otherReceipt['publicId']) . '/approve',
            ['expectedRowVersion' => $otherReceipt['rowVersion']],
            $world['teAdmin'],
        ));
        self::assertSame(200, $adminApprove->getStatusCode(), (string) $adminApprove->getBody());
    }

    public function test_soft_delete_restore_and_config_duplicate_customers(): void
    {
        $app = $this->appWithWorld();
        $world = $this->worldUsers($app);
        $created = $this->createDraft($app, [
            'vendorName' => 'Officeworks Pty Ltd',
            'totalAmount' => '9.00',
            'gstAmount' => '0.82',
            'currency' => 'AUD',
            'category' => '64100',
            'receiptDate' => '2026-09-01',
        ]);

        $dup = $app->handle($this->jsonRequest('POST', '/api/v1/tri-expense/duplicate-check', [
            'vendorName' => 'Officeworks',
            'receiptDate' => '2026-09-01',
        ]));
        $dupPayload = TestAppFactory::json($dup);
        self::assertSame(200, $dup->getStatusCode());
        self::assertTrue(Payload::obj($dupPayload['data'])['warning']);

        $config = $app->handle(TestAppFactory::request('GET', '/api/v1/tri-expense/config'));
        $configPayload = TestAppFactory::json($config);
        self::assertSame(200, $config->getStatusCode());
        self::assertSame(Module::MAX_UPLOAD_BYTES, Payload::obj($configPayload['data'])['maxUploadBytes']);
        self::assertFalse(Payload::obj(Payload::obj($configPayload['data'])['features'])['ocr']);
        self::assertFalse(Payload::obj(Payload::obj($configPayload['data'])['features'])['emailNotifications']);

        $deleted = $app->handle($this->jsonRequest('POST', '/api/v1/tri-expense/receipts/' . Payload::str(Payload::obj($created['data'])['publicId']) . '/delete', [
            'expectedRowVersion' => $created['data']['rowVersion'],
        ]));
        $deletedPayload = TestAppFactory::json($deleted);
        self::assertSame(200, $deleted->getStatusCode(), (string) $deleted->getBody());
        self::assertNotNull(Payload::obj($deletedPayload['data'])['deletedAt']);

        $ownerRestore = $app->handle($this->jsonRequest('POST', '/api/v1/tri-expense/receipts/' . Payload::str(Payload::obj($created['data'])['publicId']) . '/restore', [
            'expectedRowVersion' => Payload::obj($deletedPayload['data'])['rowVersion'],
        ]));
        self::assertSame(403, $ownerRestore->getStatusCode());

        $restored = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/tri-expense/receipts/' . Payload::str(Payload::obj($created['data'])['publicId']) . '/restore',
            ['expectedRowVersion' => Payload::obj($deletedPayload['data'])['rowVersion']],
            $world['finance'],
        ));
        self::assertSame(200, $restored->getStatusCode(), (string) $restored->getBody());

        $customerPublicId = $this->createCustomer($app, 'TE Lookup Co', 'TE-LOOK-1');
        $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/admin/customers/' . $customerPublicId . '/contacts',
            ['displayName' => 'Pat Contact', 'email' => 'pat@example.com'],
        ));
        $search = $app->handle(TestAppFactory::request('GET', '/api/v1/tri-expense/customers?q=TE-LOOK-1'));
        $searchPayload = TestAppFactory::json($search);
        self::assertSame(200, $search->getStatusCode());
        self::assertNotEmpty(Payload::obj($searchPayload['data'])['items']);

        $contacts = $app->handle(TestAppFactory::request('GET', '/api/v1/tri-expense/customers/TE-LOOK-1/contacts'));
        $contactsPayload = TestAppFactory::json($contacts);
        self::assertSame(200, $contacts->getStatusCode(), (string) $contacts->getBody());
        self::assertSame('Pat Contact', Payload::obj(Payload::list(Payload::obj($contactsPayload['data'])['items'])[0])['displayName']);
    }

    public function test_attachment_initiate_complete_and_authorized_read(): void
    {
        InMemoryProductBlobStore::resetSharedForTests();
        $app = $this->appWithWorld();
        $world = $this->worldUsers($app);
        $created = $this->createDraft($app);

        $initiate = $app->handle($this->jsonRequest('POST', '/api/v1/tri-expense/receipts/' . Payload::str(Payload::obj($created['data'])['publicId']) . '/attachment/initiate', [
            'fileName' => 'lunch.jpg',
            'contentType' => 'image/jpeg',
            'byteSize' => 2048,
        ]));
        $ticket = Payload::obj(TestAppFactory::json($initiate)['data']);
        self::assertSame(201, $initiate->getStatusCode(), (string) $initiate->getBody());
        self::assertSame(Module::BLOB_CONTAINER, $ticket['container']);
        self::assertNotEmpty($ticket['uploadUrl']);
        self::assertSame('PUT', $ticket['httpMethod']);

        $blobs = $this->blobStore($app);
        $blobs->putObject(Payload::str($ticket['container']), Payload::str($ticket['blobPath']), 'image/jpeg', 'fake-bytes');

        $complete = $app->handle($this->jsonRequest('POST', '/api/v1/tri-expense/receipts/' . Payload::str(Payload::obj($created['data'])['publicId']) . '/attachment/complete', [
            'blobPath' => $ticket['blobPath'],
            'fileName' => 'lunch.jpg',
            'expectedRowVersion' => $created['data']['rowVersion'],
        ]));
        $completed = TestAppFactory::json($complete);
        self::assertSame(200, $complete->getStatusCode(), (string) $complete->getBody());
        self::assertSame('new_azure', Payload::obj(Payload::obj($completed['data'])['attachment'])['dualReadClass']);

        $file = $app->handle(TestAppFactory::request('GET', '/api/v1/tri-expense/receipts/' . Payload::str(Payload::obj($created['data'])['publicId']) . '/file'));
        $filePayload = TestAppFactory::json($file);
        self::assertSame(200, $file->getStatusCode());
        self::assertNotEmpty(Payload::obj($filePayload['data'])['url']);
        self::assertSame('GET', Payload::obj($filePayload['data'])['httpMethod']);

        $strangerFile = $app->handle(
            TestAppFactory::request('GET', '/api/v1/tri-expense/receipts/' . Payload::str(Payload::obj($created['data'])['publicId']) . '/file')
                ->withHeader('X-Dev-Entra-Subject', $world['stranger']['subject'])
                ->withHeader('X-Dev-Entra-Email', $world['stranger']['email']),
        );
        self::assertSame(403, $strangerFile->getStatusCode());
    }

    public function test_other_user_cannot_patch_owner_draft(): void
    {
        $app = $this->appWithWorld();
        $world = $this->worldUsers($app);
        $created = $this->createDraft($app);
        $response = $app->handle($this->jsonRequest(
            'PATCH',
            '/api/v1/tri-expense/receipts/' . Payload::str(Payload::obj($created['data'])['publicId']),
            ['vendorName' => 'Hijack', 'expectedRowVersion' => $created['data']['rowVersion']],
            $world['stranger'],
        ));
        self::assertSame(403, $response->getStatusCode());
    }

    /**
     * @param array<string, mixed> $payload
     * @param array{email?:string,subject?:string}|null $headers
     * @return array{status:int,data:array<string,mixed>}
     * @param App<ContainerInterface> $app
     */
    private function createDraft(App $app, array $payload = [], ?array $headers = null): array
    {
        $defaults = [
            'vendorName' => 'Cafe',
            'totalAmount' => '20.00',
            'currency' => 'AUD',
            'category' => '66700',
        ];
        if (!array_key_exists('gstAmount', $payload) && (($payload['gstType'] ?? '') !== Module::GST_INCLUSIVE)) {
            $defaults['gstAmount'] = '1.82';
        }
        $body = array_merge($defaults, $payload);
        $response = $app->handle($this->jsonRequest('POST', '/api/v1/tri-expense/receipts', $body, $headers ?? []));
        $decoded = TestAppFactory::json($response);
        /** @var array<string, mixed> $data */
        $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];

        return ['status' => $response->getStatusCode(), 'data' => $data];
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

    /**
     * @return App<ContainerInterface>
     */
    private function appWithWorld(): App
    {
        $app = TestAppFactory::create();
        $users = $this->users($app);
        $manager = $this->registerTeUser($users, 'mgr@tricab.com', 'development-te-mgr');
        $stranger = $this->registerTeUser($users, 'stranger@tricab.com', 'development-te-stranger');
        $finance = $this->registerTeUser($users, 'finance@tricab.com', 'development-te-finance');
        $teAdmin = $this->registerTeUser($users, 'teadmin@tricab.com', 'development-te-admin');

        $this->seedProfile($app, $manager->id()->toInt(), 'mgr', 'mgr@tricab.com');
        $this->seedProfile($app, $stranger->id()->toInt(), 'stranger', 'stranger@tricab.com', managerUserId: $manager->id()->toInt());
        $this->seedProfile($app, $finance->id()->toInt(), 'finance', 'finance@tricab.com', finance: true, managerUserId: $manager->id()->toInt());
        $this->seedProfile($app, $teAdmin->id()->toInt(), 'teadmin', 'teadmin@tricab.com', admin: true, managerUserId: $manager->id()->toInt());
        $this->seedProfile($app, 1, 'thilan', 'thilanh@tricab.com', managerUserId: $manager->id()->toInt());

        return $app;
    }

    /**
     * @return array<string, array{userId:int,email:string,subject:string}>
     * @param App<ContainerInterface> $app
     */
    private function worldUsers(App $app): array
    {
        $users = $this->users($app);
        $manager = $users->findByEmail(EmailAddress::fromString('mgr@tricab.com'));
        $stranger = $users->findByEmail(EmailAddress::fromString('stranger@tricab.com'));
        $finance = $users->findByEmail(EmailAddress::fromString('finance@tricab.com'));
        $teAdmin = $users->findByEmail(EmailAddress::fromString('teadmin@tricab.com'));
        self::assertNotNull($manager);
        self::assertNotNull($stranger);
        self::assertNotNull($finance);
        self::assertNotNull($teAdmin);

        return [
            'manager' => ['userId' => $manager->id()->toInt(), 'email' => 'mgr@tricab.com', 'subject' => 'development-te-mgr'],
            'stranger' => ['userId' => $stranger->id()->toInt(), 'email' => 'stranger@tricab.com', 'subject' => 'development-te-stranger'],
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

    /**
     * @param App<ContainerInterface> $app
     */
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

    /**
     * @param App<ContainerInterface> $app
     */
    private function users(App $app): InMemoryApiUserRepository
    {
        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $repository = $container->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $repository);

        return $repository;
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function profiles(App $app): InMemoryExpenseProfileRepository
    {
        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $profiles = $container->get(ExpenseProfileRepository::class);
        self::assertInstanceOf(InMemoryExpenseProfileRepository::class, $profiles);

        return $profiles;
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function blobStore(App $app): InMemoryProductBlobStore
    {
        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $store = $container->get(ProductBlobStore::class);
        self::assertInstanceOf(InMemoryProductBlobStore::class, $store);

        return $store;
    }

    /**
     * @param list<string> $permissionCodes
     * @param App<ContainerInterface> $app
     */
    private function replacePermissions(App $app, array $permissionCodes): void
    {
        $this->users($app)->replacePermissions(1, $permissionCodes, ['EMPLOYEE']);
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function createCustomer(App $app, string $name, string $code): string
    {
        $response = $app->handle($this->jsonRequest('POST', '/api/v1/admin/customers', [
            'customerName' => $name,
            'customerCode' => $code,
        ]));
        $payload = TestAppFactory::json($response);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        /** @var array<string, mixed> $data */
        $data = $payload['data'];
        self::assertIsString($data['publicId']);

        return $data['publicId'];
    }
}
