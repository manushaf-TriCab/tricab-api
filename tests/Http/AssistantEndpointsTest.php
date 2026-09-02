<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductStore;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Identity\Contract\UserRepository;

final class AssistantEndpointsTest extends TestCase
{
    private const FAMILY_PUBLIC_ID = '11111111-1111-1111-1111-111111111111';

    private const REGION_PUBLIC_ID = '22222222-2222-2222-2222-222222222222';

    public function test_public_assistant_does_not_require_authentication(): void
    {
        $app = TestAppFactory::create(['DEV_AUTH_ENABLED' => 'false']);

        $response = $app->handle($this->jsonRequest('POST', '/api/v1/assistant/messages', [
            'message' => 'Which cable is suitable for this application?',
            'searchQuery' => 'nonexistent cable',
        ]));
        $payload = TestAppFactory::json($response);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        /** @var array<string, mixed> $data */
        $data = $payload['data'];
        self::assertIsArray($data['conversation']);
        self::assertIsArray($data['message']);
        self::assertIsArray($data['provider']);
        $conversation = $data['conversation'];
        $message = $data['message'];
        $provider = $data['provider'];
        self::assertFalse($conversation['persisted']);
        self::assertSame('assistant', $message['role']);
        self::assertSame([], $message['cards']);
        self::assertSame('deterministic-search', $provider['mode']);
        self::assertSame(['public-product-search'], $data['capabilities']);
        self::assertSame([
            'visibility' => 'public',
            'includesUnpublished' => false,
        ], $data['catalogue']);
    }

    public function test_staff_with_product_read_can_search_draft_products(): void
    {
        $app = TestAppFactory::create();
        $this->seedReferenceData($app);
        $this->createDraftProduct($app, 'DFA', 'Multi Core VSD/VFD Braided');

        $response = $app->handle($this->jsonRequest('POST', '/api/v1/assistant/messages', [
            'message' => 'Show me cables suitable for a VSD motor.',
            'searchQuery' => 'DFA',
        ]));
        $payload = TestAppFactory::json($response);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertIsArray($payload['data']);
        $data = $payload['data'];
        self::assertSame([
            'visibility' => 'staff',
            'includesUnpublished' => true,
        ], $data['catalogue']);
        self::assertSame(
            ['public-product-search', 'staff-product-search'],
            $data['capabilities'],
        );
        self::assertIsArray($data['message']);
        self::assertIsArray($data['message']['cards']);
        $cards = $data['message']['cards'];
        self::assertCount(1, $cards);
        self::assertIsArray($cards[0]);
        $card = $cards[0];
        self::assertSame('Multi Core VSD/VFD Braided', $card['title']);
        self::assertSame('draft', $card['statusCode']);
        self::assertFalse($card['isPublished']);
        self::assertIsString($card['href']);
        self::assertStringStartsWith('/admin/products/', $card['href']);
        self::assertStringContainsString('/customer-view', $card['href']);
        self::assertIsString($data['message']['text']);
        self::assertStringContainsString('currently draft', $data['message']['text']);
    }

    public function test_authenticated_user_without_product_read_remains_published_only(): void
    {
        $app = TestAppFactory::create();
        $this->seedReferenceData($app);
        $this->createDraftProduct($app, 'DFA', 'Multi Core VSD/VFD Braided');
        $this->replacePermissions($app, ['ACCOUNT.LOGIN']);

        $response = $app->handle($this->jsonRequest('POST', '/api/v1/assistant/messages', [
            'message' => 'Tell me about DFA cable.',
            'searchQuery' => 'DFA',
        ]));
        $payload = TestAppFactory::json($response);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertIsArray($payload['data']);
        $data = $payload['data'];
        self::assertSame([
            'visibility' => 'public',
            'includesUnpublished' => false,
        ], $data['catalogue']);
        self::assertIsArray($data['message']);
        self::assertSame([], $data['message']['cards']);
    }

    public function test_assistant_returns_only_published_product_card_fields(): void
    {
        $app = TestAppFactory::create();
        $this->seedReferenceData($app);
        $this->createPublishedProductWithSlug($app, 'kl-cable', 'KL Cable');

        $response = $app->handle($this->jsonRequest('POST', '/api/v1/assistant/messages', [
            'message' => 'Tell me about the KL cable.',
            'searchQuery' => 'KL',
        ]));
        $payload = TestAppFactory::json($response);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        /** @var array<string, mixed> $data */
        $data = $payload['data'];
        self::assertIsArray($data['message']);
        $message = $data['message'];
        self::assertIsArray($message['cards']);
        /** @var list<array<string, mixed>> $cards */
        $cards = $message['cards'];
        self::assertCount(1, $cards);
        self::assertSame('product', $cards[0]['type']);
        self::assertSame('KL Cable', $cards[0]['title']);
        self::assertSame('/products/plugs-sockets/industrial/kl-cable', $cards[0]['href']);
        self::assertArrayNotHasKey('rowVersion', $cards[0]);
        self::assertArrayNotHasKey('erpReference', $cards[0]);
        self::assertArrayNotHasKey('pricing', $cards[0]);
    }

    public function test_natural_language_fire_question_uses_interpreted_terms_and_published_descriptions(): void
    {
        $app = TestAppFactory::create();
        $this->seedReferenceData($app);
        $this->createPublishedProductWithSlug(
            $app,
            'emergency-circuit-cable',
            'Emergency Circuit Cable',
            'A Fire Resistant cable for critical circuits.',
        );

        $response = $app->handle($this->jsonRequest('POST', '/api/v1/assistant/messages', [
            'message' => 'What fire rated cables do you have?',
        ]));
        $payload = TestAppFactory::json($response);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertIsArray($payload['data']);
        $data = $payload['data'];
        self::assertIsArray($data['message']);
        self::assertIsArray($data['provider']);
        $message = $data['message'];
        $provider = $data['provider'];
        self::assertIsArray($message['cards']);
        $cards = $message['cards'];
        self::assertCount(1, $cards);
        self::assertIsArray($cards[0]);
        self::assertSame('Emergency Circuit Cable', $cards[0]['title']);
        self::assertSame(
            'product-assistant-query-rules-v1',
            $provider['queryInstructionVersion'],
        );
    }

    public function test_recognised_query_without_published_matches_returns_targeted_clarification(): void
    {
        $app = TestAppFactory::create(['DEV_AUTH_ENABLED' => 'false']);

        $response = $app->handle($this->jsonRequest('POST', '/api/v1/assistant/messages', [
            'message' => 'What fire rated cables do you have?',
        ]));
        $payload = TestAppFactory::json($response);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertIsArray($payload['data']);
        $data = $payload['data'];
        self::assertIsArray($data['message']);
        $message = $data['message'];
        self::assertIsString($message['text']);
        self::assertStringContainsString('Fire Resistant', $message['text']);
        self::assertStringContainsString('What voltage', $message['text']);
        self::assertSame([], $message['cards']);
    }

    public function test_assistant_rejects_invalid_messages(): void
    {
        $app = TestAppFactory::create(['DEV_AUTH_ENABLED' => 'false']);

        $response = $app->handle($this->jsonRequest('POST', '/api/v1/assistant/messages', [
            'message' => '   ',
        ]));
        $payload = TestAppFactory::json($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertIsArray($payload['error']);
        $error = $payload['error'];
        self::assertSame('VALIDATION_FAILED', $error['code']);
    }

    public function test_assistant_continues_an_existing_in_memory_conversation(): void
    {
        $app = TestAppFactory::create(['DEV_AUTH_ENABLED' => 'false']);

        $firstResponse = $app->handle($this->jsonRequest('POST', '/api/v1/assistant/messages', [
            'message' => 'Tell me about KL cable.',
            'searchQuery' => 'KL',
        ]));
        $first = TestAppFactory::json($firstResponse);
        self::assertIsArray($first['data']);
        $firstData = $first['data'];
        self::assertIsArray($firstData['conversation']);
        $firstConversation = $firstData['conversation'];
        self::assertIsString($firstConversation['publicId']);
        $conversationId = $firstConversation['publicId'];

        $secondResponse = $app->handle($this->jsonRequest('POST', '/api/v1/assistant/messages', [
            'message' => 'What applications does it support?',
            'searchQuery' => 'KL',
            'conversationId' => $conversationId,
        ]));
        $second = TestAppFactory::json($secondResponse);
        self::assertIsArray($second['data']);
        $secondData = $second['data'];
        self::assertIsArray($secondData['conversation']);
        $secondConversation = $secondData['conversation'];

        self::assertSame(200, $secondResponse->getStatusCode());
        self::assertSame($conversationId, $secondConversation['publicId']);
        self::assertFalse($secondConversation['persisted']);
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function createPublishedProductWithSlug(
        App $app,
        string $slug,
        string $productName,
        ?string $shortDescription = null,
    ): void
    {
        $create = $app->handle($this->jsonRequest('POST', '/api/v1/admin/products', [
            'productName' => $productName,
            'familyPublicId' => self::FAMILY_PUBLIC_ID,
            'shortDescription' => $shortDescription,
        ]));
        self::assertSame(201, $create->getStatusCode(), (string) $create->getBody());
        $created = TestAppFactory::json($create);
        /** @var array<string, mixed> $createdData */
        $createdData = $created['data'];
        self::assertIsString($createdData['publicId']);
        self::assertIsString($createdData['rowVersion']);
        $publicId = $createdData['publicId'];
        $rowVersion = $createdData['rowVersion'];

        $markets = $app->handle($this->jsonRequest('PUT', '/api/v1/admin/products/' . $publicId . '/markets', [
            'regionPublicIds' => [self::REGION_PUBLIC_ID],
            'marketPublicIds' => [],
        ]));
        self::assertSame(200, $markets->getStatusCode());

        $publish = $app->handle($this->jsonRequest('POST', '/api/v1/admin/products/' . $publicId . '/publish', [
            'expectedRowVersion' => $rowVersion,
        ]));
        self::assertSame(200, $publish->getStatusCode(), (string) $publish->getBody());

        $slugResponse = $app->handle($this->jsonRequest('POST', '/api/v1/admin/products/' . $publicId . '/slugs', [
            'newSlug' => $slug,
        ]));
        self::assertSame(200, $slugResponse->getStatusCode(), (string) $slugResponse->getBody());
    }

    /** @param App<ContainerInterface> $app */
    private function createDraftProduct(App $app, string $internalCode, string $productName): void
    {
        $create = $app->handle($this->jsonRequest('POST', '/api/v1/admin/products', [
            'productName' => $productName,
            'internalCode' => $internalCode,
            'familyPublicId' => self::FAMILY_PUBLIC_ID,
            'shortDescription' => 'Staff-safe draft catalogue information.',
        ]));

        self::assertSame(201, $create->getStatusCode(), (string) $create->getBody());
    }

    /**
     * @param App<ContainerInterface> $app
     * @param list<string> $permissions
     */
    private function replacePermissions(App $app, array $permissions): void
    {
        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $repository = $container->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $repository);
        $repository->replacePermissions(1, $permissions, ['EMPLOYEE']);
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function seedReferenceData(App $app): void
    {
        $store = $this->productStore($app);
        $store->addFamily([
            'publicId' => self::FAMILY_PUBLIC_ID,
            'code' => 'FAM',
            'name' => 'Test Family',
            'displayOrder' => 1,
            'isActive' => true,
        ]);
        $store->addRegion([
            'publicId' => self::REGION_PUBLIC_ID,
            'code' => 'ANZ',
            'name' => 'Australia/NZ',
            'displayOrder' => 1,
            'isActive' => true,
        ]);
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function productStore(App $app): InMemoryProductStore
    {
        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $store = $container->get(InMemoryProductStore::class);
        self::assertInstanceOf(InMemoryProductStore::class, $store);

        return $store;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function jsonRequest(string $method, string $path, array $body): ServerRequestInterface
    {
        $request = TestAppFactory::request($method, $path)
            ->withHeader('Content-Type', 'application/json');
        $request->getBody()->write(json_encode($body, JSON_THROW_ON_ERROR));

        return $request;
    }
}
