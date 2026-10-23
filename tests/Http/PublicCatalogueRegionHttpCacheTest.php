<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use TriCab\Api\Identity\Email\DevelopmentGuestOtpEmailSender;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductStore;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Identity\Contract\GuestOtpEmailSender;
use TriCab\Core\Identity\Contract\UserProvisioningRepository;
use TriCab\Core\Identity\Domain\Service\GoogleExternalIdentityFactory;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\PermissionCode;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;
use TriCab\Core\Product\Contract\CatalogueNavigationRepository;

/**
 * A catalogue response for one region must never be reusable for another, by a browser or by
 * a shared cache. The fixture product is available in AU only.
 */
final class PublicCatalogueRegionHttpCacheTest extends TestCase
{
    private const FAMILY_PUBLIC_ID = '11111111-1111-1111-1111-111111111111';
    private const REGION_AU = 'a0000000-0000-4000-8000-000000000001';
    private const REGION_US = 'c0000000-0000-4000-8000-000000000003';
    private const ALLOWED_ORIGIN = 'http://localhost:5173';

    /** @var App<ContainerInterface> */
    private App $app;

    private string $productPublicId;

    private ?string $customerToken = null;

    protected function setUp(): void
    {
        $this->app = TestAppFactory::create();
        $this->productPublicId = $this->seedCatalogue();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function endpoints(): iterable
    {
        yield 'panel' => ['/api/v1/catalogue/panel?tabSlug=products&includeProducts=true'];
        yield 'tree' => ['/api/v1/catalogue/tabs/products/tree?includeProducts=true'];
        yield 'node' => ['/api/v1/catalogue/nodes/control-cables?tabSlug=products'];
    }

    #[DataProvider('endpoints')]
    public function test_explicit_region_is_publicly_cacheable_for_anonymous_callers(string $path): void
    {
        $response = $this->get($path . '&regionCode=AU');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertStringStartsWith('public, max-age=60', $response->getHeaderLine('Cache-Control'));
        self::assertStringNotContainsStringIgnoringCase('cookie', $response->getHeaderLine('Vary'));
        self::assertContains($this->productPublicId, $this->productIds($response));
    }

    #[DataProvider('endpoints')]
    public function test_cookie_region_is_private_revalidated_and_varies_by_cookie(string $path): void
    {
        $response = $this->get($path, cookieRegion: 'AU');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('private, no-cache', $response->getHeaderLine('Cache-Control'));
        self::assertSame('Cookie', $response->getHeaderLine('Vary'));
        self::assertContains($this->productPublicId, $this->productIds($response));
    }

    #[DataProvider('endpoints')]
    public function test_default_region_for_an_anonymous_first_visit_is_private_and_revalidated(string $path): void
    {
        $response = $this->get($path);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('private, no-cache', $response->getHeaderLine('Cache-Control'));
        self::assertSame('Cookie', $response->getHeaderLine('Vary'));
    }

    #[DataProvider('endpoints')]
    public function test_switching_cookie_region_never_revalidates_the_previous_regions_copy(string $path): void
    {
        $au = $this->get($path, cookieRegion: 'AU');
        self::assertSame(200, $au->getStatusCode(), (string) $au->getBody());
        $auEtag = $au->getHeaderLine('ETag');
        self::assertNotSame('', $auEtag);

        $sameRegion = $this->get($path, cookieRegion: 'AU', ifNoneMatch: $auEtag);
        self::assertSame(304, $sameRegion->getStatusCode());

        $us = $this->get($path, cookieRegion: 'US', ifNoneMatch: $auEtag);
        self::assertNotSame(304, $us->getStatusCode(), 'An AU copy must not be confirmed for a US visitor');
        self::assertNotSame($auEtag, $us->getHeaderLine('ETag'));
        if ($us->getStatusCode() === 200) {
            self::assertNotContains($this->productPublicId, $this->productIds($us));
        } else {
            self::assertSame(404, $us->getStatusCode(), (string) $us->getBody());
        }
    }

    public function test_identical_payloads_in_different_regions_still_get_different_etags(): void
    {
        $path = '/api/v1/catalogue/tabs/guides/tree?includeProducts=true';
        $au = $this->get($path . '&regionCode=AU');
        $us = $this->get($path . '&regionCode=US');
        self::assertSame(200, $au->getStatusCode(), (string) $au->getBody());
        self::assertSame(200, $us->getStatusCode(), (string) $us->getBody());
        self::assertSame($this->data($au), $this->data($us), 'fixture precondition: same body in both regions');

        self::assertNotSame($au->getHeaderLine('ETag'), $us->getHeaderLine('ETag'));
        self::assertSame(
            200,
            $this->get($path . '&regionCode=US', ifNoneMatch: $au->getHeaderLine('ETag'))->getStatusCode(),
        );
    }

    #[DataProvider('endpoints')]
    public function test_browser_revalidation_returns_304_with_the_same_cache_policy(string $path): void
    {
        $explicit = $this->get($path . '&regionCode=AU');
        $explicit304 = $this->get($path . '&regionCode=AU', ifNoneMatch: $explicit->getHeaderLine('ETag'));
        self::assertSame(304, $explicit304->getStatusCode());
        self::assertSame('', (string) $explicit304->getBody());
        self::assertSame($explicit->getHeaderLine('Cache-Control'), $explicit304->getHeaderLine('Cache-Control'));

        $cookie = $this->get($path, cookieRegion: 'AU');
        $cookie304 = $this->get($path, cookieRegion: 'AU', ifNoneMatch: $cookie->getHeaderLine('ETag'));
        self::assertSame(304, $cookie304->getStatusCode());
        self::assertSame('private, no-cache', $cookie304->getHeaderLine('Cache-Control'));
        self::assertSame('Cookie', $cookie304->getHeaderLine('Vary'));
    }

    #[DataProvider('endpoints')]
    public function test_authenticated_customers_get_the_same_payload_but_never_a_public_response(string $path): void
    {
        $anonymous = $this->get($path . '&regionCode=AU');
        $authenticated = $this->get($path . '&regionCode=AU', authorization: 'Bearer ' . $this->customerToken());

        self::assertSame(200, $authenticated->getStatusCode(), (string) $authenticated->getBody());
        self::assertSame($this->data($anonymous), $this->data($authenticated), 'payload must not depend on the caller');
        self::assertSame($anonymous->getHeaderLine('ETag'), $authenticated->getHeaderLine('ETag'));
        self::assertStringStartsWith('private, max-age=60', $authenticated->getHeaderLine('Cache-Control'));

        $authenticatedCookie = $this->get($path, cookieRegion: 'AU', authorization: 'Bearer ' . $this->customerToken());
        self::assertSame('private, no-cache', $authenticatedCookie->getHeaderLine('Cache-Control'));
    }

    public function test_cors_keeps_vary_cookie_and_adds_origin(): void
    {
        $response = $this->get(
            '/api/v1/catalogue/panel?tabSlug=products',
            cookieRegion: 'AU',
            origin: self::ALLOWED_ORIGIN,
        );

        self::assertSame(self::ALLOWED_ORIGIN, $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('Cookie, Origin', $response->getHeaderLine('Vary'));

        $explicit = $this->get('/api/v1/catalogue/panel?tabSlug=products&regionCode=AU', origin: self::ALLOWED_ORIGIN);
        self::assertSame('Origin', $explicit->getHeaderLine('Vary'));
    }

    private function get(
        string $path,
        ?string $cookieRegion = null,
        ?string $ifNoneMatch = null,
        ?string $authorization = null,
        ?string $origin = null,
    ): ResponseInterface {
        $request = TestAppFactory::request('GET', $path);
        if ($cookieRegion !== null) {
            $request = $request->withCookieParams(['tricab_region' => $cookieRegion]);
        }
        if ($ifNoneMatch !== null) {
            $request = $request->withHeader('If-None-Match', $ifNoneMatch);
        }
        if ($authorization !== null) {
            $request = $request->withHeader('Authorization', $authorization);
        }
        if ($origin !== null) {
            $request = $request->withHeader('Origin', $origin);
        }

        return $this->app->handle($request);
    }

    private function customerToken(): string
    {
        if ($this->customerToken !== null) {
            return $this->customerToken;
        }
        $email = 'customer@example.com';
        $users = $this->service(UserProvisioningRepository::class);
        $users->register(
            emailAddress: EmailAddress::fromString($email),
            displayName: 'Portal Customer',
            status: UserStatus::active(),
            roles: [RoleCode::fromString('GUEST')],
            permissions: [PermissionCode::fromString('ACCOUNT.LOGIN')],
            identity: GoogleExternalIdentityFactory::forSubject('seed-' . $email),
        );
        $challengeId = $this->stringField(
            $this->send('POST', '/api/v1/auth/guest/request-otp', ['email' => $email], 202),
            'challengeId',
        );
        $sender = $this->service(GuestOtpEmailSender::class);
        self::assertInstanceOf(DevelopmentGuestOtpEmailSender::class, $sender);
        $otp = $sender->lastMessage()['otpCode'] ?? null;
        self::assertIsString($otp);
        $token = $this->stringField(
            $this->send('POST', '/api/v1/auth/guest/verify-otp', [
                'challengeId' => $challengeId,
                'email' => $email,
                'otp' => $otp,
            ], 200),
            'accessToken',
        );

        $me = $this->app->handle(TestAppFactory::request('GET', '/api/v1/me')->withHeader('Authorization', 'Bearer ' . $token));
        self::assertSame(200, $me->getStatusCode(), (string) $me->getBody());

        return $this->customerToken = $token;
    }

    private function seedCatalogue(): string
    {
        $store = $this->service(InMemoryProductStore::class);
        $store->addFamily([
            'publicId' => self::FAMILY_PUBLIC_ID,
            'code' => 'FAM',
            'name' => 'Test Family',
            'displayOrder' => 1,
            'isActive' => true,
        ]);
        foreach ([[self::REGION_AU, 'AU', 'Australia'], [self::REGION_US, 'US', 'United States']] as [$id, $code, $name]) {
            $store->addRegion(['publicId' => $id, 'code' => $code, 'name' => $name, 'displayOrder' => 1, 'isActive' => true]);
        }

        $productPublicId = $this->stringField(
            $this->send('POST', '/api/v1/admin/products', [
                'productName' => 'Control Cable',
                'familyPublicId' => self::FAMILY_PUBLIC_ID,
            ], 201),
            'publicId',
        );
        $this->send('PUT', '/api/v1/admin/products/' . $productPublicId . '/markets', [
            'regionPublicIds' => [self::REGION_AU],
            'marketPublicIds' => [],
        ], 200);
        $rowVersion = $this->stringField(
            $this->app->handle(TestAppFactory::request('GET', '/api/v1/admin/products/' . $productPublicId)),
            'rowVersion',
        );
        $this->send('POST', '/api/v1/admin/products/' . $productPublicId . '/publish', [
            'expectedRowVersion' => $rowVersion,
        ], 200);

        $repo = $this->service(CatalogueNavigationRepository::class);
        $actor = UserId::fromInt(1);
        $tab = $repo->createTab(['tabCode' => 'PRODUCTS', 'tabName' => 'Products', 'slug' => 'products'], $actor);
        $repo->createTab(['tabCode' => 'GUIDES', 'tabName' => 'Guides', 'slug' => 'guides'], $actor);
        $node = $repo->createNode([
            'tabPublicId' => $tab->publicId,
            'nodeCode' => 'CTRL',
            'nodeName' => 'Control Cables',
            'slug' => 'control-cables',
        ], $actor);
        $repo->replaceNodeProducts($node->publicId, [
            ['productPublicId' => $productPublicId, 'displayOrder' => 1],
        ], $actor);

        return $productPublicId;
    }

    /**
     * Product public IDs anywhere in the response, whichever endpoint shape it has.
     *
     * @return list<string>
     */
    private function productIds(ResponseInterface $response): array
    {
        $ids = [];
        $walk = static function (mixed $value) use (&$walk, &$ids): void {
            if (!is_array($value)) {
                return;
            }
            if (isset($value['products']) && is_array($value['products'])) {
                foreach ($value['products'] as $product) {
                    if (is_array($product) && is_string($product['publicId'] ?? null)) {
                        $ids[] = $product['publicId'];
                    }
                }
            }
            foreach ($value as $child) {
                $walk($child);
            }
        };
        $walk($this->data($response));

        return $ids;
    }

    /**
     * @return array<mixed>
     */
    private function data(ResponseInterface $response): array
    {
        $data = TestAppFactory::json($response)['data'] ?? null;
        self::assertIsArray($data, (string) $response->getBody());

        return $data;
    }

    private function stringField(ResponseInterface $response, string $key): string
    {
        $value = $this->data($response)[$key] ?? null;
        self::assertIsString($value, $key . ': ' . (string) $response->getBody());

        return $value;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function send(string $method, string $path, array $body, int $expectedStatus): ResponseInterface
    {
        $request = TestAppFactory::request($method, $path)->withHeader('Content-Type', 'application/json');
        $request->getBody()->write(json_encode($body, JSON_THROW_ON_ERROR));
        $response = $this->app->handle($request);
        self::assertSame($expectedStatus, $response->getStatusCode(), (string) $response->getBody());

        return $response;
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    private function service(string $id): object
    {
        $container = $this->app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $service = $container->get($id);
        self::assertInstanceOf($id, $service);

        return $service;
    }
}
