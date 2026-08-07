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
 * In-memory blob store for BLOB_AUTH_MODE=memory / disabled→memory in development.
 * Bytes are kept in a process-static map and mirrored under the local temp directory
 * so php -S restarts and subsequent requests can still serve complete-upload payloads.
 */
final class InMemoryProductBlobStore implements ProductBlobStore
{
    /** @var array<string, array{contentType: string, binary: string, etag: string}> */
    private static array $sharedObjects = [];

    public function __construct(
        private readonly string $cdnBaseUrl = '',
        private readonly string $defaultContainer = 'products',
        private readonly ?string $persistDirectory = null,
    ) {
    }

    public static function resetSharedForTests(): void
    {
        self::$sharedObjects = [];
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
        $object = $this->readObject($container, $blobPath);
        if ($object === null) {
            return null;
        }

        return new ProductBlobObjectInfo(
            exists: true,
            contentType: $object['contentType'],
            contentLength: strlen($object['binary']),
            etag: $object['etag'],
            contentMd5: null,
        );
    }

    /**
     * @return array{contentType: string, binary: string, etag: string}|null
     */
    public function readObject(string $container, string $blobPath): ?array
    {
        $key = $this->key($container, $blobPath);
        if (isset(self::$sharedObjects[$key])) {
            return self::$sharedObjects[$key];
        }

        $fromDisk = $this->readPersisted($key);
        if ($fromDisk !== null) {
            self::$sharedObjects[$key] = $fromDisk;

            return $fromDisk;
        }

        return null;
    }

    public function deleteObject(string $container, string $blobPath): void
    {
        $key = $this->key($container, $blobPath);
        unset(self::$sharedObjects[$key]);
        $this->deletePersisted($key);
    }

    public function copyObject(
        string $sourceContainer,
        string $sourcePath,
        string $destContainer,
        string $destPath,
    ): void {
        $source = $this->readObject($sourceContainer, $sourcePath);
        if ($source === null) {
            throw new LogicException('Source blob not found for copy.');
        }
        $this->putObject($destContainer, $destPath, $source['contentType'], $source['binary']);
    }

    public function putObject(
        string $container,
        string $blobPath,
        string $contentType,
        string $binary,
    ): ProductBlobObjectInfo {
        $etag = bin2hex(random_bytes(8));
        $object = [
            'contentType' => $contentType,
            'binary' => $binary,
            'etag' => $etag,
        ];
        $key = $this->key($container, $blobPath);
        self::$sharedObjects[$key] = $object;
        $this->writePersisted($key, $object);

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

    public function hasObject(string $container, string $blobPath): bool
    {
        return $this->readObject($container, $blobPath) !== null;
    }

    private function key(string $container, string $blobPath): string
    {
        return $container . '|' . ltrim($blobPath, '/');
    }

    private function persistRoot(): string
    {
        if ($this->persistDirectory !== null && $this->persistDirectory !== '') {
            return rtrim($this->persistDirectory, '/');
        }

        return rtrim(sys_get_temp_dir(), '/') . '/tricab-dev-product-blobs';
    }

    /**
     * @param array{contentType: string, binary: string, etag: string} $object
     */
    private function writePersisted(string $key, array $object): void
    {
        $root = $this->persistRoot();
        if (!is_dir($root) && !mkdir($root, 0700, true) && !is_dir($root)) {
            return;
        }

        $base = $root . '/' . hash('sha256', $key);
        @file_put_contents($base . '.bin', $object['binary']);
        @file_put_contents($base . '.json', json_encode([
            'contentType' => $object['contentType'],
            'etag' => $object['etag'],
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{contentType: string, binary: string, etag: string}|null
     */
    private function readPersisted(string $key): ?array
    {
        $base = $this->persistRoot() . '/' . hash('sha256', $key);
        $binPath = $base . '.bin';
        $metaPath = $base . '.json';
        if (!is_file($binPath) || !is_file($metaPath)) {
            return null;
        }

        $binary = @file_get_contents($binPath);
        $metaRaw = @file_get_contents($metaPath);
        if ($binary === false || $metaRaw === false) {
            return null;
        }

        try {
            /** @var array<string, mixed> $meta */
            $meta = json_decode($metaRaw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        $contentType = is_string($meta['contentType'] ?? null) ? $meta['contentType'] : 'application/octet-stream';
        $etag = is_string($meta['etag'] ?? null) ? $meta['etag'] : bin2hex(random_bytes(4));

        return [
            'contentType' => $contentType,
            'binary' => $binary,
            'etag' => $etag,
        ];
    }

    private function deletePersisted(string $key): void
    {
        $base = $this->persistRoot() . '/' . hash('sha256', $key);
        @unlink($base . '.bin');
        @unlink($base . '.json');
    }
}
