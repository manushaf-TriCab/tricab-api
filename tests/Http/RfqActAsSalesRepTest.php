<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Slim\App;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Identity\Domain\Entity\ExternalIdentity;
use TriCab\Core\Identity\Domain\Entity\User;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\ExternalProvider;
use TriCab\Core\Identity\Domain\ValueObject\ExternalSubjectId;
use TriCab\Core\Identity\Domain\ValueObject\ExternalTenantId;
use TriCab\Core\Identity\Domain\ValueObject\PermissionCode;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;
use TriCab\Core\Rfq\Application\Service\RfqReviewService;
use TriCab\Core\Rfq\Contract\RfqDraftStore;
use TriCab\Core\Rfq\Contract\RfqOperatorDirectory;
use TriCab\Core\Rfq\Infrastructure\InMemoryRfqRepository;
use TriCab\Core\Rfq\Module;
use TriCab\Core\Rfq\Parser\ParseSeal;
use TriCab\Core\Rfq\Storage\DraftActor;
use TriCab\Core\Rfq\Storage\NewDraft;
use TriCab\Core\Rfq\Storage\StoreProjection;
use TriCab\Core\Rfq\Storage\StoredDraft;
use TriCab\Core\Tests\Identity\Doubles\FixedClock;
use TriCab\Core\Tests\Rfq\Application\ReadRfqQueueServiceTest;
use TriCab\Core\Tests\Rfq\Fixtures\FixtureRfqParserClient;
use TriCab\Core\Tests\Rfq\Fixtures\RecordingCustomerDirectory;

/**
 * Act-as plan A2: "an RFQ save while switched makes the target the sales rep".
 *
 * RFQ identifies people by email and attributes a save to the last person to
 * save it ({@see \TriCab\Core\Rfq\Storage\SalesRepAttribution}). While an
 * administrator is switched, the request's user is the target, so the target
 * is the worker the store is handed, and the administrator is not.
 */
final class RfqActAsSalesRepTest extends TestCase
{
    private const DRAFT_ID = '0123456789ab4def8123456789abcdef';
    private const UID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa1';

    /** @var list<DraftActor> */
    private array $workers = [];

    /** @var list<string> */
    private array $operatorLookups = [];

    protected function setUp(): void
    {
        $this->workers = [];
        $this->operatorLookups = [];
    }

    public function test_an_rfq_save_while_switched_makes_the_target_the_sales_rep(): void
    {
        $app = $this->app();
        $target = $this->registerSales($app, 'sales-rep-target');
        $sessionId = $this->startSwitch($app, $target);

        $response = $app->handle($this->save()->withHeader('X-Act-As-Session', $sessionId));

        self::assertNotSame(403, $response->getStatusCode(), (string) $response->getBody());
        self::assertCount(1, $this->workers, 'The save reached the store.');
        self::assertSame('sales-rep-target@tricab.com', $this->workers[0]->email);
        self::assertSame('sales', $this->workers[0]->role);
        self::assertSame('REP:sales-rep-target@tricab.com', $this->workers[0]->operatorCode);
        self::assertSame(['sales-rep-target@tricab.com'], array_values(array_unique($this->operatorLookups)));
    }

    public function test_the_same_save_without_a_switch_is_attributed_to_the_signed_in_user(): void
    {
        $app = $this->app();
        $users = $app->getContainer()->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $users);
        $users->replacePermissions(1, ['account.login', ...Module::ROLE_PERMISSIONS[Module::ROLE_SALES]], ['EMPLOYEE']);

        $response = $app->handle($this->save());

        self::assertNotSame(403, $response->getStatusCode(), (string) $response->getBody());
        self::assertCount(1, $this->workers);
        self::assertNotSame('sales-rep-target@tricab.com', $this->workers[0]->email);
    }

    /**
     * @return App<ContainerInterface>
     */
    private function app(): App
    {
        $app = TestAppFactory::create();
        $container = $app->getContainer();
        self::assertInstanceOf(\DI\Container::class, $container);

        $stored = new StoredDraft(self::DRAFT_ID, 3, 'draft', [], ['customer_id' => '12', 'region' => 'australia', 'currency' => 'AUD'], [], [[
            'line_uid' => self::UID,
            'raw_text' => '3C 2.5',
            'source' => ['original_text' => '3C 2.5', 'input_type' => 'pasted_text'],
            'final_product_code' => 'TPS3C25',
            'selected_product_code' => 'TPS3C25',
            'length' => ['number_of_lengths' => 1, 'length_each' => 50, 'total_length' => 50],
            'status' => 'matched',
            'final_status' => 'matched',
            'pricing' => ['status' => 'available', 'region' => 'australia', 'quote_currency' => 'AUD', 'cost_status' => 'STOCK', 'unit_price' => 4.0],
            'unit_price' => 4.0,
            'final_unit_price' => 8.0,
            'line_total' => 400.0,
            'margin_percent' => 50,
        ]], []);
        $store = new class ($stored, function (DraftActor $worker): void {
            $this->workers[] = $worker;
        }) implements RfqDraftStore {
            /** @param \Closure(DraftActor): void $saved */
            public function __construct(private readonly StoredDraft $stored, private readonly \Closure $saved)
            {
            }

            public function create(NewDraft $draft): string
            {
                throw new \LogicException('not used');
            }

            public function saveContent(string $draftId, int $expectedRevision, array $quoteDetails, array $rows, string $nextStatus, DraftActor $actor, string $viewMode = ''): int
            {
                ($this->saved)($actor);

                return $expectedRevision + 1;
            }

            public function load(string $draftId): ?StoredDraft
            {
                return $draftId === $this->stored->id ? $this->stored : null;
            }
        };
        $operators = new class (function (string $email): void {
            $this->operatorLookups[] = $email;
        }) implements RfqOperatorDirectory {
            /** @param \Closure(string): void $asked */
            public function __construct(private readonly \Closure $asked)
            {
            }

            public function codeFor(string $email): string
            {
                ($this->asked)($email);

                return 'REP:' . $email;
            }
        };
        // Everyone who can see an RFQ can work it, so who owns the record does not matter here.
        $repository = (new InMemoryRfqRepository())
            ->withRecord(ReadRfqQueueServiceTest::record(StoreProjection::publicId(self::DRAFT_ID), 1, 'VIC'));
        $container->set(\TriCab\Core\Rfq\Contract\RfqRepository::class, $repository);
        $container->set(RfqReviewService::class, new RfqReviewService(
            new FixtureRfqParserClient(),
            $store,
            $repository,
            $operators,
            new RecordingCustomerDirectory(),
            FixedClock::utc('2026-10-02T02:00:00'),
            new ParseSeal('test-parser-token'),
        ));

        return $app;
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function registerSales(App $app, string $subject): User
    {
        $container = $app->getContainer();
        self::assertInstanceOf(\DI\Container::class, $container);
        $users = $container->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $users);

        return $users->register(
            EmailAddress::fromString($subject . '@tricab.com'),
            $subject,
            UserStatus::active(),
            [RoleCode::fromString('EMPLOYEE')],
            array_map(
                static fn (string $code): PermissionCode => PermissionCode::fromString($code),
                ['account.login', ...Module::ROLE_PERMISSIONS[Module::ROLE_SALES]],
            ),
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
    private function startSwitch(App $app, User $target): string
    {
        $request = TestAppFactory::request('POST', '/api/v1/admin/act-as')->withHeader('Content-Type', 'application/json');
        $request->getBody()->write(json_encode([
            'userPublicId' => $target->publicId()->toString(),
            'reason' => 'Checking who a switched RFQ save is credited to',
        ], JSON_THROW_ON_ERROR));
        $response = $app->handle($request);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $data = TestAppFactory::json($response)['data'] ?? null;
        self::assertIsArray($data);
        self::assertIsString($data['sessionId'] ?? null);

        return $data['sessionId'];
    }

    private function save(): \Psr\Http\Message\ServerRequestInterface
    {
        return TestAppFactory::request('PATCH', '/api/v1/rfqs/' . StoreProjection::publicId(self::DRAFT_ID))
            ->withParsedBody([
                'expectedRevision' => 3,
                'lines' => [['lineUid' => self::UID, 'comment' => 'checked']],
            ]);
    }
}
