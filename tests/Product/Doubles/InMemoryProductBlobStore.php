<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Doubles;

use DateTimeImmutable;
use DateTimeZone;
use TriCab\Core\Product\Application\DTO\ProductBlobObjectInfo;
use TriCab\Core\Product\Application\DTO\ProductBlobWriteTicket;
use TriCab\Core\Product\Contract\ProductBlobStore;

final class InMemoryProductBlobStore implements ProductBlobStore
{
    /** @var array<string, array{contentType: string, binary: string, etag: string}> */
    private array $objects = [];

    private string $publicBaseUrl;

    public function __construct(string $publicBaseUrl = 'https://cdn.test')
    {
        $this->publicBaseUrl = rtrim($publicBaseUrl, '/');
    }

    public function issueWriteUpload(
        string $container,
        string $blobPath,
        string $contentType,
        int $expiresInSeconds,
    ): ProductBlobWriteTicket {
        $expires = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify(sprintf('+%d seconds', $expiresInSeconds));

        return new ProductBlobWriteTicket(
            uploadUrl: sprintf('https://blob.test/%s/%s?sig=write', rawurlencode($container), $blobPath),
            httpMethod: 'PUT',
            requiredHeaders: [
                'Content-Type' => $contentType,
                'x-ms-blob-type' => 'BlockBlob',
            ],
            expiresAtUtc: $expires->format('Y-m-d\TH:i:s\Z'),
            container: $container,
            blobPath: $blobPath,
        );
    }

    public function issueReadDownload(
        string $container,
        string $blobPath,
        int $expiresInSeconds,
    ): ProductBlobWriteTicket {
        $expires = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify(sprintf('+%d seconds', $expiresInSeconds));

        return new ProductBlobWriteTicket(
            uploadUrl: sprintf('https://blob.test/%s/%s?sig=read', rawurlencode($container), $blobPath),
            httpMethod: 'GET',
            requiredHeaders: [],
            expiresAtUtc: $expires->format('Y-m-d\TH:i:s\Z'),
            container: $container,
            blobPath: $blobPath,
        );
    }

    public function headObject(string $container, string $blobPath): ?ProductBlobObjectInfo
    {
        $key = $this->key($container, $blobPath);
        if (!isset($this->objects[$key])) {
            return null;
        }

        $object = $this->objects[$key];

        return new ProductBlobObjectInfo(
            exists: true,
            contentType: $object['contentType'],
            contentLength: strlen($object['binary']),
            etag: $object['etag'],
            contentMd5: base64_encode(md5($object['binary'], true)),
        );
    }

    public function deleteObject(string $container, string $blobPath): void
    {
        unset($this->objects[$this->key($container, $blobPath)]);
    }

    public function copyObject(
        string $sourceContainer,
        string $sourcePath,
        string $destContainer,
        string $destPath,
    ): void {
        $sourceKey = $this->key($sourceContainer, $sourcePath);
        if (!isset($this->objects[$sourceKey])) {
            return;
        }

        $this->objects[$this->key($destContainer, $destPath)] = $this->objects[$sourceKey];
    }

    public function putObject(
        string $container,
        string $blobPath,
        string $contentType,
        string $binary,
    ): ProductBlobObjectInfo {
        $etag = '"' . md5($binary) . '"';
        $this->objects[$this->key($container, $blobPath)] = [
            'contentType' => $contentType,
            'binary' => $binary,
            'etag' => $etag,
        ];

        return new ProductBlobObjectInfo(
            exists: true,
            contentType: $contentType,
            contentLength: strlen($binary),
            etag: $etag,
            contentMd5: base64_encode(md5($binary, true)),
        );
    }

    public function buildPublicUrl(string $container, string $blobPath): ?string
    {
        if ($container === '' || $blobPath === '') {
            return null;
        }

        return sprintf('%s/%s/%s', $this->publicBaseUrl, rawurlencode($container), $blobPath);
    }

    public function getBinary(string $container, string $blobPath): ?string
    {
        return $this->objects[$this->key($container, $blobPath)]['binary'] ?? null;
    }

    private function key(string $container, string $blobPath): string
    {
        return $container . "\0" . $blobPath;
    }
}
