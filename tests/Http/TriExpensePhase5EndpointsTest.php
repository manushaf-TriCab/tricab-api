<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use TriCab\Api\Expense\Persistence\DisabledExpenseCaptureAssistPort;
use TriCab\Api\Expense\Persistence\InMemoryExpenseProfileRepository;
use TriCab\Api\Expense\Persistence\NullExpenseNotificationPort;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Product\Storage\InMemoryProductBlobStore;
use TriCab\Api\Tests\Payload;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Product\Contract\ProductBlobStore;
use TriCab\Core\Expense\Contract\ExpenseCaptureAssistPort;
use TriCab\Core\Expense\Contract\ExpenseNotificationPort;
use TriCab\Core\Expense\Contract\ExpenseProfileRepository;
use TriCab\Core\Expense\Domain\Entity\ExpenseProfile;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Identity\Domain\Entity\ExternalIdentity;
use TriCab\Core\Identity\Domain\Entity\User;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\ExternalProvider;
use TriCab\Core\Identity\Domain\ValueObject\ExternalSubjectId;
use TriCab\Core\Identity\Domain\ValueObject\ExternalTenantId;
use TriCab\Core\Identity\Domain\ValueObject\PermissionCode;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;

/**
 * Phase 5 HTTP surface: the OCR endpoint, and the config feature flags that
 * tell the client which integrations this environment actually has.
 */
final class TriExpensePhase5EndpointsTest extends TestCase
{
    public function test_ocrEndpointRequiresAuthentication(): void
    {
        $app = TestAppFactory::create(['DEV_AUTH_ENABLED' => 'false']);
        $response = $app->handle(TestAppFactory::request('POST', '/api/v1/tri-expense/receipts/x/ocr'));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('UNAUTHENTICATED', Payload::obj(TestAppFactory::json($response)['error'])['code']);
    }

    public function test_ocrEndpointRefusesCleanlyWhenOcrIsNotConfigured(): void
    {
        $app = $this->appWithWorld();
        $publicId = $this->createDraftWithAttachment($app);

        $response = $app->handle($this->jsonRequest('POST', '/api/v1/tri-expense/receipts/' . $publicId . '/ocr', []));

        self::assertSame(503, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('TRIEXPENSE_OCR_DISABLED', Payload::obj(TestAppFactory::json($response)['error'])['code']);
    }

    public function test_ocrEndpointDeniesAUserWhoCannotSeeTheReceipt(): void
    {
        $app = $this->appWithWorld();
        $publicId = $this->createDraftWithAttachment($app);
        $stranger = $this->worldUsers($app)['stranger'];

        $response = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/tri-expense/receipts/' . $publicId . '/ocr',
            [],
            $stranger,
        ));

        self::assertSame(403, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('TRIEXPENSE_ACCESS_DENIED', Payload::obj(TestAppFactory::json($response)['error'])['code']);
    }

    public function test_ocrEndpointReturns404ForAnUnknownReceipt(): void
    {
        $app = $this->appWithWorld();

        $response = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/tri-expense/receipts/55555555-5555-4555-8555-555555555555/ocr',
            [],
        ));

        self::assertSame(404, $response->getStatusCode(), (string) $response->getBody());
    }

    public function test_extractEndpointRequiresAuthentication(): void
    {
        $app = TestAppFactory::create(['DEV_AUTH_ENABLED' => 'false']);
        $response = $app->handle(TestAppFactory::request('POST', '/api/v1/tri-expense/receipts/extract'));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('UNAUTHENTICATED', Payload::obj(TestAppFactory::json($response)['error'])['code']);
    }

    public function test_extractEndpointRefusesCleanlyWhenOcrIsNotConfigured(): void
    {
        $app = $this->appWithWorld();
        $response = $app->handle($this->multipartExtractRequest("\xFF\xD8\xFFjpeg", 'lunch.jpg'));

        self::assertSame(503, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('TRIEXPENSE_OCR_DISABLED', Payload::obj(TestAppFactory::json($response)['error'])['code']);
    }

    public function test_extractEndpointRejectsMissingFile(): void
    {
        $app = $this->appWithWorld([
            'TRIEXPENSE_OCR_ENABLED' => 'true',
            'TRIEXPENSE_DOC_INTEL_ENDPOINT' => 'https://doc-intel.example',
            'TRIEXPENSE_DOC_INTEL_KEY' => 'test-key',
        ]);

        $response = $app->handle(TestAppFactory::request('POST', '/api/v1/tri-expense/receipts/extract')
            ->withHeader('Content-Type', 'multipart/form-data'));

        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('TRIEXPENSE_OCR_INPUT_REJECTED', Payload::obj(TestAppFactory::json($response)['error'])['code']);
    }

    public function test_extractEndpointReturnsSuggestionsFromUploadedBytes(): void
    {
        $app = $this->appWithWorld([
            'TRIEXPENSE_OCR_ENABLED' => 'true',
            'TRIEXPENSE_DOC_INTEL_ENDPOINT' => 'https://doc-intel.example',
            'TRIEXPENSE_DOC_INTEL_KEY' => 'test-key',
        ]);

        /** @var \DI\Container $container */
        $container = $app->getContainer();

        $fakePort = new class implements ExpenseCaptureAssistPort {
            public function isEnabled(): bool
            {
                return true;
            }

            public function suggestReceiptFields(string $fileContent, string $mimeType): array
            {
                if ($mimeType !== 'image/jpeg') {
                    throw new \RuntimeException('unexpected mime: ' . $mimeType);
                }
                if (!str_contains($fileContent, 'jpeg')) {
                    throw new \RuntimeException('unexpected file content');
                }

                return [
                    'fields' => [
                        'vendorName' => ['value' => 'Shell', 'confidence' => 0.9, 'source' => 'document_intelligence'],
                        'receiptDate' => ['value' => '2026-09-10', 'confidence' => 0.8, 'source' => 'document_intelligence'],
                        'totalAmount' => ['value' => '55.00', 'confidence' => 0.95, 'source' => 'document_intelligence'],
                        'gstAmount' => ['value' => '5.00', 'confidence' => 0.9, 'source' => 'document_intelligence'],
                        'currency' => ['value' => 'AUD', 'confidence' => 0.7, 'source' => 'document_intelligence'],
                    ],
                    'warnings' => [],
                    'rawText' => 'Shell fuel',
                ];
            }

            public function suggestCategory(string $vendor, string $rawText, array $categories): ?string
            {
                return $categories === [] ? null : '64100';
            }
        };

        $access = $container->get(\TriCab\Core\Expense\Application\Service\ExpenseAccessService::class);
        self::assertInstanceOf(\TriCab\Core\Expense\Application\Service\ExpenseAccessService::class, $access);
        $audit = $container->get(\TriCab\Core\Expense\Contract\ExpenseAuditRecorder::class);
        self::assertInstanceOf(\TriCab\Core\Expense\Contract\ExpenseAuditRecorder::class, $audit);
        $responder = $container->get(\TriCab\Api\Http\Response\JsonResponder::class);
        self::assertInstanceOf(\TriCab\Api\Http\Response\JsonResponder::class, $responder);
        $errors = $container->get(\TriCab\Api\Http\Auth\ExpenseErrorMapper::class);
        self::assertInstanceOf(\TriCab\Api\Http\Auth\ExpenseErrorMapper::class, $errors);
        $draftService = new \TriCab\Core\Expense\Application\Service\ExtractExpenseReceiptDraftService(
            $access,
            $fakePort,
            $audit,
        );
        $container->set(ExpenseCaptureAssistPort::class, $fakePort);
        $container->set(\TriCab\Core\Expense\Application\Service\ExtractExpenseReceiptDraftService::class, $draftService);
        $container->set(
            \TriCab\Api\Http\Action\TriExpense\ExtractExpenseReceiptDraftAction::class,
            new \TriCab\Api\Http\Action\TriExpense\ExtractExpenseReceiptDraftAction(
                $draftService,
                $responder,
                $errors,
            ),
        );

        $response = $app->handle($this->multipartExtractRequest("\xFF\xD8\xFFjpeg-bytes", 'fuel.jpg'));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $data = Payload::obj(TestAppFactory::json($response)['data']);
        self::assertFalse($data['applied']);
        self::assertArrayNotHasKey('receiptId', $data);
        $fields = Payload::obj($data['fields']);
        self::assertSame('Shell', Payload::obj($fields['vendorName'])['value']);
        self::assertSame('2026-09-10', Payload::obj($fields['receiptDate'])['value']);
        self::assertSame('55.00', Payload::obj($fields['totalAmount'])['value']);
        self::assertSame('5.00', Payload::obj($fields['gstAmount'])['value']);
        self::assertSame('AUD', Payload::obj($fields['currency'])['value']);
        self::assertSame('64100', Payload::obj($fields['categoryCode'])['value']);
        self::assertSame('openai', Payload::obj($fields['categoryCode'])['source']);
    }

    public function test_extractEndpointRejectsUnsupportedExtension(): void
    {
        $app = $this->appWithWorld([
            'TRIEXPENSE_OCR_ENABLED' => 'true',
            'TRIEXPENSE_DOC_INTEL_ENDPOINT' => 'https://doc-intel.example',
            'TRIEXPENSE_DOC_INTEL_KEY' => 'test-key',
        ]);
        /** @var \DI\Container $container */
        $container = $app->getContainer();
        $container->set(ExpenseCaptureAssistPort::class, new class implements ExpenseCaptureAssistPort {
            public function isEnabled(): bool
            {
                return true;
            }

            public function suggestReceiptFields(string $fileContent, string $mimeType): array
            {
                throw new \RuntimeException('must not extract unsupported files');
            }

            public function suggestCategory(string $vendor, string $rawText, array $categories): ?string
            {
                return null;
            }
        });

        $response = $app->handle($this->multipartExtractRequest('hello', 'notes.txt'));
        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('TRIEXPENSE_OCR_INPUT_REJECTED', Payload::obj(TestAppFactory::json($response)['error'])['code']);
    }

    public function test_configReportsIntegrationsOffUntilTheyAreConfigured(): void
    {
        $app = $this->appWithWorld();
        $data = Payload::obj(TestAppFactory::json($app->handle(
            TestAppFactory::request('GET', '/api/v1/tri-expense/config'),
        ))['data']);
        $features = Payload::obj($data['features']);

        self::assertFalse($features['ocr']);
        self::assertFalse($features['emailNotifications']);
        // Phase 4 capabilities need no external service, so they stay on.
        self::assertTrue($features['bankReconciliation']);
        self::assertTrue($features['periodClose']);
        self::assertArrayHasKey('costCentres', $data);
        self::assertArrayHasKey('costCentresAvailable', $data);
        self::assertIsArray($data['costCentres']);
        self::assertIsBool($data['costCentresAvailable']);
    }

    public function test_configReportsIntegrationsOnWhenCredentialsArePresent(): void
    {
        $app = $this->appWithWorld([
            'TRIEXPENSE_OCR_ENABLED' => 'true',
            'TRIEXPENSE_DOC_INTEL_ENDPOINT' => 'https://doc-intel.example',
            'TRIEXPENSE_DOC_INTEL_KEY' => 'test-key',
            'TRIEXPENSE_EMAIL_ENABLED' => 'true',
            'GRAPH_TENANT_ID' => 'tenant',
            'GRAPH_CLIENT_ID' => 'client',
            'GRAPH_CLIENT_SECRET' => 'secret',
        ]);

        $features = Payload::obj(Payload::obj(TestAppFactory::json($app->handle(
            TestAppFactory::request('GET', '/api/v1/tri-expense/config'),
        ))['data'])['features']);

        self::assertTrue($features['ocr']);
        self::assertTrue($features['emailNotifications']);
    }

    public function test_emailFlagStaysOffWithoutGraphCredentials(): void
    {
        // The flag alone must not promise the client that mail will arrive.
        $app = $this->appWithWorld(['TRIEXPENSE_EMAIL_ENABLED' => 'true']);

        $features = Payload::obj(Payload::obj(TestAppFactory::json($app->handle(
            TestAppFactory::request('GET', '/api/v1/tri-expense/config'),
        ))['data'])['features']);

        self::assertFalse($features['emailNotifications']);
    }

    public function test_defaultEnvironmentBindsTheInertAdapters(): void
    {
        $container = $this->appWithWorld()->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);

        self::assertInstanceOf(NullExpenseNotificationPort::class, $container->get(ExpenseNotificationPort::class));
        self::assertInstanceOf(DisabledExpenseCaptureAssistPort::class, $container->get(ExpenseCaptureAssistPort::class));
    }

    /**
     * @param array<string, string|null> $env
     * @return App<ContainerInterface>
     */
    private function appWithWorld(array $env = []): App
    {
        InMemoryProductBlobStore::resetSharedForTests();
        $app = TestAppFactory::create($env);
        $users = $this->users($app);
        $manager = $this->registerTeUser($users, 'mgr@tricab.com', 'development-te-mgr');
        $stranger = $this->registerTeUser($users, 'stranger@tricab.com', 'development-te-stranger');

        $this->seedProfile($app, $manager->id()->toInt(), 'mgr', 'mgr@tricab.com');
        $this->seedProfile($app, $stranger->id()->toInt(), 'stranger', 'stranger@tricab.com');
        $this->seedProfile($app, 1, 'thilan', 'thilanh@tricab.com', managerUserId: $manager->id()->toInt());

        return $app;
    }

    /**
     * @param App<ContainerInterface> $app
     * @return array<string, array{email:string,subject:string}>
     */
    private function worldUsers(App $app): array
    {
        return [
            'stranger' => ['email' => 'stranger@tricab.com', 'subject' => 'development-te-stranger'],
            'manager' => ['email' => 'mgr@tricab.com', 'subject' => 'development-te-mgr'],
        ];
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function createDraftWithAttachment(App $app): string
    {
        $created = Payload::obj(TestAppFactory::json($app->handle($this->jsonRequest('POST', '/api/v1/tri-expense/receipts', [
            'vendorName' => 'Cafe',
            'totalAmount' => '20.00',
            'gstAmount' => '1.82',
            'currency' => 'AUD',
            'category' => '66700',
        ])))['data']);

        $ticket = Payload::obj(TestAppFactory::json($app->handle($this->jsonRequest(
            'POST',
            '/api/v1/tri-expense/receipts/' . Payload::str($created['publicId']) . '/attachment/initiate',
            ['fileName' => 'lunch.jpg', 'contentType' => 'image/jpeg', 'byteSize' => 2048],
        )))['data']);

        // Stand in for the client's direct-to-blob upload.
        $this->blobStore($app)->putObject(Payload::str($ticket['container']), Payload::str($ticket['blobPath']), 'image/jpeg', 'fake-bytes');

        $completed = TestAppFactory::json($app->handle($this->jsonRequest(
            'POST',
            '/api/v1/tri-expense/receipts/' . Payload::str($created['publicId']) . '/attachment/complete',
            [
                'blobPath' => $ticket['blobPath'],
                'fileName' => 'lunch.jpg',
                'expectedRowVersion' => $created['rowVersion'],
            ],
        )));
        self::assertSame('new_azure', Payload::obj(Payload::obj($completed['data'])['attachment'])['dualReadClass']);

        return Payload::str($created['publicId']);
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
     * @param array{email?:string,subject?:string} $actor
     */
    private function multipartExtractRequest(string $bytes, string $fileName, array $actor = []): ServerRequestInterface
    {
        $stream = (new \Slim\Psr7\Factory\StreamFactory())->createStream($bytes);
        $upload = new \Slim\Psr7\UploadedFile(
            $stream,
            $fileName,
            'application/octet-stream',
            strlen($bytes),
            UPLOAD_ERR_OK,
        );

        $request = TestAppFactory::request('POST', '/api/v1/tri-expense/receipts/extract')
            ->withUploadedFiles(['file' => $upload]);
        if (isset($actor['subject'])) {
            $request = $request->withHeader('X-Dev-Entra-Subject', $actor['subject']);
        }
        if (isset($actor['email'])) {
            $request = $request->withHeader('X-Dev-Entra-Email', $actor['email']);
        }

        return $request;
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
        $this->profiles($app)->save(new ExpenseProfile(
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

    private function registerTeUser(InMemoryApiUserRepository $users, string $email, string $subject): User
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
    private function blobStore(App $app): InMemoryProductBlobStore
    {
        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $store = $container->get(ProductBlobStore::class);
        self::assertInstanceOf(InMemoryProductBlobStore::class, $store);

        return $store;
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
}
