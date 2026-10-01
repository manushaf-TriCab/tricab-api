<?php

declare(strict_types=1);

namespace TriCab\Api\CustomerContactReport\Storage;

use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportStoredFile;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportFileStoreFailure;
use TriCab\Core\CustomerContactReport\Contract\CustomerContactReportFileStore;

/**
 * Test and local store. Nothing here is an Azure blob.
 */
final class InMemoryCustomerContactReportFileStore implements CustomerContactReportFileStore
{
    /** @var array<string, string> */
    public array $objects = [];

    /** @var list<string> */
    public array $deleted = [];

    /** @var list<string> */
    public array $deleteAttempts = [];

    public bool $failNextPut = false;

    public bool $failNextDelete = false;

    public function put(string $blobPath, string $contentType, string $bytes): CustomerContactReportStoredFile
    {
        unset($contentType);
        if ($this->failNextPut) {
            $this->failNextPut = false;
            throw new CustomerContactReportFileStoreFailure('put failed');
        }
        if (isset($this->objects[$blobPath])) {
            throw new CustomerContactReportFileStoreFailure('blob already exists');
        }
        $this->objects[$blobPath] = $bytes;

        return new CustomerContactReportStoredFile($blobPath, 'memory://' . $blobPath);
    }

    public function delete(string $blobPath): void
    {
        $this->deleteAttempts[] = $blobPath;
        if ($this->failNextDelete) {
            $this->failNextDelete = false;
            throw new CustomerContactReportFileStoreFailure('delete failed');
        }
        unset($this->objects[$blobPath]);
        $this->deleted[] = $blobPath;
    }
}
