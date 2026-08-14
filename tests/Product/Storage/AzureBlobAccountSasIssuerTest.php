<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Product\Storage;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Product\Storage\AzureBlobAccountSasIssuer;

final class AzureBlobAccountSasIssuerTest extends TestCase
{
    public function test_write_sas_is_blob_scoped_https_create_write_only(): void
    {
        // Deterministic disposable key material for signature shape tests only.
        $key = base64_encode(str_repeat('a', 32));
        $issuer = new AzureBlobAccountSasIssuer(
            'tricabdevstore',
            $key,
            'https://tricabdevstore.blob.core.windows.net',
        );

        $issued = $issuer->issueBlobWriteSas(
            'products',
            'products/9970e6f0-f091-f111-b337-000d3ae094e4/3d/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee/model/cable.glb',
            'model/gltf-binary',
            900,
        );

        $url = $issued['url'];
        $parts = parse_url($url);
        self::assertSame('https', $parts['scheme'] ?? null);
        self::assertSame('tricabdevstore.blob.core.windows.net', $parts['host'] ?? null);
        self::assertStringContainsString('/products/products/', $parts['path'] ?? '');
        self::assertStringEndsWith('/model/cable.glb', $parts['path'] ?? '');

        parse_str($parts['query'] ?? '', $query);
        self::assertSame('cw', $query['sp'] ?? null);
        self::assertSame('b', $query['sr'] ?? null);
        self::assertSame('https', $query['spr'] ?? null);
        self::assertSame('model/gltf-binary', $query['rsct'] ?? null);
        self::assertArrayHasKey('sig', $query);
        self::assertArrayHasKey('se', $query);
        self::assertArrayNotHasKey('ss', $query);
        self::assertArrayNotHasKey('srt', $query);
        self::assertSame(['Content-Type', 'x-ms-blob-type'], array_keys($issued['headers']));
        self::assertSame('BlockBlob', $issued['headers']['x-ms-blob-type']);
    }

    public function test_read_sas_is_read_only_blob_scoped(): void
    {
        $key = base64_encode(str_repeat('b', 32));
        $issuer = new AzureBlobAccountSasIssuer(
            'tricabdevstore',
            $key,
            'https://tricabdevstore.blob.core.windows.net',
        );
        $issued = $issuer->issueBlobReadSas('products', 'products/x/3d/y/model/z.glb', 300);
        parse_str(parse_url($issued['url'], PHP_URL_QUERY) ?: '', $query);
        self::assertSame('r', $query['sp'] ?? null);
        self::assertSame('b', $query['sr'] ?? null);
        self::assertSame('https', $query['spr'] ?? null);
        self::assertArrayNotHasKey('ss', $query);
        self::assertArrayNotHasKey('srt', $query);

        $before = time();
        $expiry = strtotime((string) ($query['se'] ?? ''));
        self::assertIsInt($expiry);
        self::assertGreaterThanOrEqual($before + 300 - 5, $expiry);
        self::assertLessThanOrEqual(time() + 300 + 5, $expiry);
    }
}
