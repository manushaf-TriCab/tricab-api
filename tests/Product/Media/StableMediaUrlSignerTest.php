<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Product\Media;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use TriCab\Api\Config\Settings;
use TriCab\Api\Http\Action\PublicProduct\GetStableMediaImageAction;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Product\Media\StableMediaUrlSigner;
use TriCab\Api\Tests\Product\Storage\RecordingBlobStore;

final class StableMediaUrlSignerTest extends TestCase
{
    private const BLOB = 'https://tricabv2.blob.core.windows.net/products/products/abc/images/def/cross-section.png';
    private const ORIGIN = 'https://api.tricab.test';

    public function test_stable_url_is_permanent_and_carries_no_read_signature(): void
    {
        $signer = $this->signer();
        $signedBlob = self::BLOB . '?sp=r&sr=b&se=2026-01-01T00%3A00%3A00Z&sig=expired';

        $fromUnsigned = $signer->stableUrlForBlobUrl(self::BLOB, self::ORIGIN);
        $fromExpired = $signer->stableUrlForBlobUrl($signedBlob, self::ORIGIN);

        self::assertIsString($fromUnsigned);
        self::assertSame($fromUnsigned, $fromExpired);
        self::assertStringStartsWith(self::ORIGIN . StableMediaUrlSigner::PATH_PREFIX, $fromUnsigned);
        self::assertStringEndsWith('/cross-section.png', $fromUnsigned);
        self::assertStringNotContainsString('sig=', $fromUnsigned);
        self::assertStringNotContainsString('se=', $fromUnsigned);
    }

    public function test_token_round_trips_to_the_same_blob(): void
    {
        $signer = $this->signer();
        $url = (string) $signer->stableUrlForBlobUrl(self::BLOB, self::ORIGIN);

        self::assertSame(
            ['container' => 'products', 'path' => 'products/abc/images/def/cross-section.png'],
            $signer->verify($this->token($url)),
        );
        self::assertSame(self::BLOB, $signer->blobUrlForStableUrl($url));
    }

    public function test_tampered_or_foreign_tokens_are_rejected(): void
    {
        $signer = $this->signer();
        $token = $this->token((string) $signer->stableUrlForBlobUrl(self::BLOB, self::ORIGIN));
        [$payload, $mac] = explode('.', $token);
        $otherPayload = rtrim(strtr(base64_encode("products\nproducts/other/secret.png"), '+/', '-_'), '=');

        self::assertNull($signer->verify($otherPayload . '.' . $mac));
        self::assertNull($signer->verify($payload . '.' . strrev($mac)));
        self::assertNull($signer->verify($payload));
        self::assertNull($signer->verify('not a token'));
        self::assertNull($signer->verify(''));
        self::assertNull($this->signer(['MEDIA_URL_SIGNING_SECRET' => 'another-secret-value'])->verify($token));
    }

    public function test_only_own_blob_images_get_stable_urls(): void
    {
        $signer = $this->signer();

        self::assertNull($signer->stableUrlForBlobUrl('https://cdn.example.com/products/a.png', self::ORIGIN));
        self::assertNull($signer->stableUrlForBlobUrl('https://tricabv2.blob.core.windows.net/products/a/spec.pdf', self::ORIGIN));
        self::assertNull($signer->stableUrlForBlobUrl('/assets/BV.png', self::ORIGIN));
        self::assertNull($signer->stableUrlForBlobUrl(self::BLOB, ''));
    }

    public function test_disabled_without_connection_string_blob_mode(): void
    {
        $signer = $this->signer(['BLOB_AUTH_MODE' => 'memory']);

        self::assertFalse($signer->isEnabled());
        self::assertNull($signer->stableUrlForBlobUrl(self::BLOB, self::ORIGIN));
        self::assertSame(['imageUrl' => self::BLOB], $signer->stabilizePayload(['imageUrl' => self::BLOB], self::ORIGIN));
    }

    public function test_redirect_cache_lifetime_ends_before_the_read_signature(): void
    {
        self::assertSame('public, max-age=120', $this->signer(['BLOB_DOWNLOAD_SAS_TTL_SECONDS' => '300'])->redirectCacheControl());
        self::assertSame('public, max-age=300', $this->signer(['BLOB_DOWNLOAD_SAS_TTL_SECONDS' => '3600'])->redirectCacheControl());
        self::assertSame('no-store', $this->signer(['BLOB_DOWNLOAD_SAS_TTL_SECONDS' => '60'])->redirectCacheControl());
    }

    public function test_payload_stabilizer_rewrites_product_images_only(): void
    {
        $signer = $this->signer();
        $payload = [
            'slug' => 'bvp',
            'thumbnailUrl' => self::BLOB . '?sp=r&sig=old',
            'media' => [
                ['role' => 'gallery', 'url' => self::BLOB, 'publicId' => 'm1'],
                ['role' => 'external', 'url' => 'https://cdn.example.com/a.png'],
            ],
            'documents' => [
                ['url' => 'https://tricabv2.blob.core.windows.net/products/doc/datasheet.pdf?sig=x'],
            ],
            'catalogue' => 'https://tricabv2.blob.core.windows.net/catalogues/c/cover.png',
            'count' => 3,
        ];

        $stable = $signer->stabilizePayload($payload, self::ORIGIN);

        $expected = $signer->stableUrlForBlobUrl(self::BLOB, self::ORIGIN);
        self::assertSame($expected, $stable['thumbnailUrl']);
        self::assertSame([
            ['role' => 'gallery', 'url' => $expected, 'publicId' => 'm1'],
            $payload['media'][1],
        ], $stable['media']);
        self::assertSame($payload['documents'], $stable['documents']);
        self::assertSame($payload['catalogue'], $stable['catalogue']);
        self::assertSame(3, $stable['count']);
    }

    public function test_origin_prefers_configuration_then_forwarded_headers(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'http://internal:8080/api/v1/catalogue/panel');

        self::assertSame('http://internal:8080', $this->signer()->originFor($request));
        self::assertSame('https://api.tricab.ai', $this->signer()->originFor(
            $request->withHeader('X-Forwarded-Proto', 'https')->withHeader('X-Forwarded-Host', 'api.tricab.ai'),
        ));
        self::assertSame('', $this->signer()->originFor($request->withHeader('X-Forwarded-Host', 'evil.test/<script>')));
        self::assertSame('https://api.fixed.test', $this->signer(['PUBLIC_API_ORIGIN' => 'https://api.fixed.test'])->originFor(
            $request->withHeader('X-Forwarded-Host', 'other.test'),
        ));
    }

    public function test_redirect_issues_a_fresh_read_signature_each_time(): void
    {
        $blobs = new RecordingBlobStore();
        $signer = $this->signer();
        $action = $this->action($signer, $blobs);
        $stable = (string) $signer->stableUrlForBlobUrl(self::BLOB, self::ORIGIN);

        $response = $action($this->routeRequest($this->token($stable)), new \Slim\Psr7\Response());

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('public, max-age=120', $response->getHeaderLine('Cache-Control'));
        self::assertSame('products', $blobs->lastContainer);
        self::assertSame('products/abc/images/def/cross-section.png', $blobs->lastPath);
        self::assertSame(300, $blobs->lastTtl);
        $location = $response->getHeaderLine('Location');
        self::assertStringStartsWith(self::BLOB . '?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $se = $query['se'] ?? '';
        self::assertIsString($se);
        $expiry = strtotime($se);
        self::assertIsInt($expiry);
        self::assertGreaterThanOrEqual(time() + 295, $expiry);
    }

    public function test_redirect_rejects_invalid_tokens_and_other_containers(): void
    {
        $blobs = new RecordingBlobStore();
        $signer = $this->signer();
        $action = $this->action($signer, $blobs);
        $catalogueBlob = 'https://tricabv2.blob.core.windows.net/catalogues/c/cover.png';
        $otherContainerToken = $this->token((string) $signer->stableUrlForBlobUrl($catalogueBlob, self::ORIGIN));

        foreach (['garbage', 'YQ.YQ', $otherContainerToken] as $token) {
            $response = $action($this->routeRequest($token), new \Slim\Psr7\Response());
            self::assertSame(404, $response->getStatusCode(), $token);
            self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        }
        self::assertNull($blobs->lastPath);
    }

    public function test_redirect_failure_is_not_cached(): void
    {
        $blobs = new RecordingBlobStore();
        $blobs->failNextIssueReadDownload = true;
        $signer = $this->signer();
        $stable = (string) $signer->stableUrlForBlobUrl(self::BLOB, self::ORIGIN);

        $response = $this->action($signer, $blobs)($this->routeRequest($this->token($stable)), new \Slim\Psr7\Response());

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    /**
     * @param array<string, string> $overrides
     */
    private function signer(array $overrides = []): StableMediaUrlSigner
    {
        return new StableMediaUrlSigner(self::settings($overrides));
    }

    private function action(StableMediaUrlSigner $signer, RecordingBlobStore $blobs): GetStableMediaImageAction
    {
        return new GetStableMediaImageAction($signer, $blobs, self::settings([]), new JsonResponder());
    }

    /**
     * @param array<string, string> $overrides
     */
    public static function settings(array $overrides): Settings
    {
        return Settings::fromEnvironment(array_merge([
            'APP_ENV' => 'development',
            'APP_DEBUG' => 'true',
            'DEV_AUTH_ENABLED' => 'true',
            'USER_REPOSITORY' => 'memory',
            'GUEST_EMAIL_PROVIDER' => 'development',
            'GUEST_JWT_ISSUER' => 'https://api.tricab.local',
            'GUEST_JWT_AUDIENCE' => 'tricab-api',
            'GUEST_JWT_SECRET' => 'local-development-guest-jwt-secret-32b',
            'BLOB_AUTH_MODE' => 'connection-string',
            'BLOB_ACCOUNT_NAME' => 'tricabv2',
            'BLOB_ACCOUNT_KEY' => base64_encode(str_repeat('c', 32)),
            'BLOB_ENDPOINT' => 'https://tricabv2.blob.core.windows.net',
            'BLOB_CONTAINER_PRODUCTS' => 'products',
            'BLOB_CDN_BASE_URL' => '',
            'BLOB_DOWNLOAD_SAS_TTL_SECONDS' => '300',
        ], $overrides));
    }

    private function token(string $stableUrl): string
    {
        $path = (string) parse_url($stableUrl, PHP_URL_PATH);

        return explode('/', substr($path, strlen(StableMediaUrlSigner::PATH_PREFIX)))[0];
    }

    private function routeRequest(string $token): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', self::ORIGIN . StableMediaUrlSigner::PATH_PREFIX . $token)
            ->withAttribute('token', $token);
    }
}
