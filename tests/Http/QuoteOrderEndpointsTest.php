<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Slim\App;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\QuoteOrder\Persistence\InMemoryQuoteOrderRepository;
use TriCab\Api\Tests\Payload;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteDetails;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteItemDetails;
use TriCab\Core\QuoteOrder\Contract\QuoteOrderRepository;
use TriCab\Core\QuoteOrder\Domain\ValueObject\QuoteOrderType;
use TriCab\Core\QuoteOrder\Module;

final class QuoteOrderEndpointsTest extends TestCase
{
    public function test_unauthenticated_is_rejected(): void
    {
        $app = TestAppFactory::create(['DEV_AUTH_ENABLED' => 'false']);
        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/quotes-orders'));
        $payload = TestAppFactory::json($response);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('UNAUTHENTICATED', Payload::obj($payload['error'])['code']);
    }

    public function test_authenticated_without_permission_is_forbidden(): void
    {
        $app = TestAppFactory::create(['QUOTES_ORDERS_LEGACY_READ_ENABLED' => 'true']);
        $this->replacePermissions($app, ['ACCOUNT.LOGIN']);

        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/quotes-orders'));
        $payload = TestAppFactory::json($response);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('INSUFFICIENT_PERMISSION', Payload::obj($payload['error'])['code']);
    }

    public function test_list_and_get_quote_with_in_memory_repo(): void
    {
        $app = TestAppFactory::create(['QUOTES_ORDERS_LEGACY_READ_ENABLED' => 'true']);
        $this->replacePermissions($app, ['ACCOUNT.LOGIN', Module::PERMISSION_READ]);
        $this->bindInMemoryRepo($app);

        $list = $app->handle(TestAppFactory::request('GET', '/api/v1/quotes-orders?pageSize=10'));
        $listPayload = TestAppFactory::json($list);
        self::assertSame(200, $list->getStatusCode());
        self::assertTrue($listPayload['success']);
        $data = Payload::obj($listPayload['data']);
        self::assertArrayHasKey('items', $data);
        self::assertArrayHasKey('page', $data);
        self::assertArrayHasKey('pageSize', $data);
        self::assertArrayHasKey('total', $data);
        self::assertGreaterThanOrEqual(1, (int) $data['total']);

        $item = Payload::obj(Payload::list($data['items'])[0]);
        self::assertArrayHasKey('totalStatus', $item);
        self::assertArrayNotHasKey('tnet', $item);
        self::assertArrayNotHasKey('tqoth', $item);

        $get = $app->handle(TestAppFactory::request('GET', '/api/v1/quotes/1001'));
        $getPayload = TestAppFactory::json($get);
        self::assertSame(200, $get->getStatusCode());
        $quote = Payload::obj($getPayload['data']);
        self::assertSame('1001', $quote['quoteId']);
        self::assertSame('open', $quote['status']);
        self::assertSame('1710', $quote['statusCode']);
        self::assertIsArray($quote['items']);
    }

    public function test_source_disabled_returns_503(): void
    {
        $app = TestAppFactory::create(['QUOTES_ORDERS_LEGACY_READ_ENABLED' => 'false']);
        $this->replacePermissions($app, ['ACCOUNT.LOGIN', Module::PERMISSION_READ]);

        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/quotes-orders'));
        $payload = TestAppFactory::json($response);

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('QUOTE_ORDER_SOURCE_UNAVAILABLE', Payload::obj($payload['error'])['code']);
    }

    public function test_no_quote_write_http_route(): void
    {
        $app = TestAppFactory::create([
            'QUOTES_ORDERS_LEGACY_READ_ENABLED' => 'true',
            'QUOTES_ORDERS_LEGACY_WRITE_ENABLED' => 'false',
        ]);
        $this->replacePermissions($app, ['ACCOUNT.LOGIN', Module::PERMISSION_READ, Module::PERMISSION_CREATE]);

        $post = $app->handle(TestAppFactory::request('POST', '/api/v1/quotes'));
        self::assertSame(404, $post->getStatusCode());

        $finalize = $app->handle(TestAppFactory::request('POST', '/api/v1/quotes/1001/finalize'));
        self::assertSame(404, $finalize->getStatusCode());

        $email = $app->handle(TestAppFactory::request('POST', '/api/v1/quotes/1001/email'));
        self::assertSame(404, $email->getStatusCode());
    }

    /**
     * @param list<string> $permissionCodes
     * @param App<ContainerInterface> $app
     */
    private function replacePermissions(App $app, array $permissionCodes): void
    {
        $this->userRepository($app)->replacePermissions(1, $permissionCodes, ['EMPLOYEE']);
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function userRepository(App $app): InMemoryApiUserRepository
    {
        /** @var \DI\Container $container */
        $container = $app->getContainer();
        $repo = $container->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $repo);

        return $repo;
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function bindInMemoryRepo(App $app): void
    {
        /** @var \DI\Container $container */
        $container = $app->getContainer();
        $repo = new InMemoryQuoteOrderRepository();
        $repo->seedQuote(new QuoteDetails(
            id: '1001',
            type: QuoteOrderType::QUOTE,
            quoteId: '1001',
            orderId: null,
            date: '2026-09-17',
            validDate: null,
            raisedBy: null,
            representative: 'Alex',
            customerCode: 'ACME01',
            customerName: 'Acme',
            contactName: null,
            reference: 'R1',
            status: 'open',
            statusCode: '1710',
            statusLabel: 'Open',
            total: '504.19',
            totalStatus: 'resolved',
            currency: 'AUD',
            items: [
                new QuoteItemDetails('1', '1', 'BVA', '1', '1', '504.19', null, []),
            ],
        ));
        $container->set(QuoteOrderRepository::class, $repo);
    }
}
