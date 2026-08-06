<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Storage;

use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use TriCab\Core\Product\Application\DTO\ProductBlobObjectInfo;
use TriCab\Core\Product\Application\DTO\ProductBlobWriteTicket;
use TriCab\Core\Product\Contract\ProductBlobStore;

/**
 * In-memory blob store for USER_REPOSITORY=memory / BLOB_AUTH_MODE=memory tests.
 * Browser upload is simulated via putObject() in tests; issueWriteUpload returns
 * a non-credential opaque URL that is not usable over HTTP.
 */
final class InMemoryProductBlobStore implements ProductBlobStore
{
    /** @var array<string, array{contentType: string, binary: string, etag: string}> */
    private array $objects = [];

    public function __construct(
        private readonly string $cdnBaseUrl = '',
        private readonly string $defaultContainer = 'products',
    ) {
    }

    public function issueWriteUpload(
        string $container,
        string $blobPath,
        string $contentType,
        int $expiresInSeconds,
    ): ProductBlobWriteTicket {
        $expires = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('+' . max(60, $expiresInSeconds) . ' seconds')
            ->format('Y-m-d\TH:i:s\Z');

        return new ProductBlobWriteTicket(
            uploadUrl: 'memory://' . rawurlencode($container) . '/' . ltrim($blobPath, '/') . '?w=1',
            httpMethod: 'PUT',
            requiredHeaders: [
                'Content-Type' => $contentType,
                'x-ms-blob-type' => 'BlockBlob',
            ],
            expiresAtUtc: $expires,
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
            ->modify('+' . max(60, $expiresInSeconds) . ' seconds')
            ->format('Y-m-d\TH:i:s\Z');

        return new ProductBlobWriteTicket(
            uploadUrl: 'memory://' . rawurlencode($container) . '/' . ltrim($blobPath, '/') . '?r=1',
            httpMethod: 'GET',
            requiredHeaders: [],
            expiresAtUtc: $expires,
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
            contentMd5: null,
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
        $source = $this->objects[$this->key($sourceContainer, $sourcePath)] ?? null;
        if ($source === null) {
            throw new LogicException('Source blob not found for copy.');
        }
        $this->objects[$this->key($destContainer, $destPath)] = $source;
    }

    public function putObject(
        string $container,
        string $blobPath,
        string $contentType,
        string $binary,
    ): ProductBlobObjectInfo {
        $etag = bin2hex(random_bytes(8));
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
            contentMd5: null,
        );
    }

    public function buildPublicUrl(string $container, string $blobPath): ?string
    {
        if ($this->cdnBaseUrl === '') {
            return null;
        }

        return rtrim($this->cdnBaseUrl, '/') . '/' . trim($container, '/') . '/' . ltrim($blobPath, '/');
    }

    public function defaultContainer(): string
    {
        return $this->defaultContainer;
    }

    private function key(string $container, string $blobPath): string
    {
        return $container . '|' . ltrim($blobPath, '/');
    }
}
