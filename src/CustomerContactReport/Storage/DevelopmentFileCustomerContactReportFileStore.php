<?php

declare(strict_types=1);

namespace TriCab\Api\CustomerContactReport\Storage;

use RuntimeException;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportStoredFile;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportFileStoreFailure;
use TriCab\Core\CustomerContactReport\Contract\CustomerContactReportFileStore;

/**
 * Development browser harness. Bytes are written under the system temp directory
 * because php -S does not keep the in-memory file store across requests.
 */
final class DevelopmentFileCustomerContactReportFileStore implements CustomerContactReportFileStore
{
    private static bool $failNextPut = false;

    public function __construct(
        private readonly string $directory,
    ) {
    }

    public static function failNextPut(): void
    {
        self::$failNextPut = true;
    }

    public function put(string $blobPath, string $contentType, string $bytes): CustomerContactReportStoredFile
    {
        if (self::$failNextPut) {
            self::$failNextPut = false;
            throw new CustomerContactReportFileStoreFailure('put failed');
        }

        $path = $this->absolute($blobPath);
        if (is_file($path)) {
            throw new CustomerContactReportFileStoreFailure('blob already exists');
        }

        $folder = dirname($path);
        if (!is_dir($folder) && !mkdir($folder, 0700, true) && !is_dir($folder)) {
            throw new CustomerContactReportFileStoreFailure('put failed');
        }

        if (file_put_contents($path, $bytes, LOCK_EX) === false) {
            throw new CustomerContactReportFileStoreFailure('put failed');
        }
        chmod($path, 0600);

        $url = str_contains($blobPath, '/sig-')
            ? 'data:' . $contentType . ';base64,' . base64_encode($bytes)
            : 'e2e://' . ltrim($blobPath, '/');

        return new CustomerContactReportStoredFile($blobPath, $url);
    }

    public function delete(string $blobPath): void
    {
        $path = $this->absolute($blobPath);
        if (!is_file($path)) {
            return;
        }
        if (!unlink($path)) {
            throw new CustomerContactReportFileStoreFailure('delete failed');
        }
    }

    private function absolute(string $blobPath): string
    {
        $relative = ltrim(str_replace('\\', '/', $blobPath), '/');
        if ($relative === '' || str_contains($relative, '..')) {
            throw new RuntimeException('Customer Contact E2E file path is not allowed.');
        }

        return rtrim($this->directory, '/') . '/' . $relative;
    }
}
