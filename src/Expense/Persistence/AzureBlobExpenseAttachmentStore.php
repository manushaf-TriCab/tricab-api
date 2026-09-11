<?php

declare(strict_types=1);

namespace TriCab\Api\Expense\Persistence;

use TriCab\Api\Config\Settings;
use TriCab\Core\Expense\Contract\ExpenseAttachmentStore;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseAttachmentRef;
use TriCab\Core\Expense\Module;
use TriCab\Core\Product\Contract\ProductBlobStore;

/**
 * SAS attachment adapter on the dedicated `triexpense` container.
 * Dual-read: new blob paths get SAS; legacy Azure URLs on the same account get SAS;
 * WordPress URLs are never returned raw.
 */
final class AzureBlobExpenseAttachmentStore implements ExpenseAttachmentStore
{
    public function __construct(
        private readonly ProductBlobStore $blobs,
        private readonly Settings $settings,
    ) {
    }

    public function describe(ExpenseAttachmentRef $ref): array
    {
        return [
            'storageSource' => $ref->storageSource(),
            'fileName' => $ref->fileName(),
            'hasReference' => $ref->reference() !== '',
            'dualReadClass' => $ref->dualReadClass(),
        ];
    }

    public function initiateWrite(string $blobPath, string $contentType, int $expiresInSeconds): array
    {
        $ticket = $this->blobs->issueWriteUpload(
            $this->container(),
            ltrim($blobPath, '/'),
            $contentType,
            $expiresInSeconds,
        );

        return [
            'uploadUrl' => $ticket->uploadUrl,
            'httpMethod' => $ticket->httpMethod,
            'requiredHeaders' => $ticket->requiredHeaders,
            'expiresAtUtc' => $ticket->expiresAtUtc,
            'container' => $ticket->container,
            'blobPath' => $ticket->blobPath,
        ];
    }

    public function issueAuthorizedRead(ExpenseAttachmentRef $ref, int $expiresInSeconds): ?array
    {
        $path = $this->managedBlobPath($ref);
        if ($path === null) {
            return null;
        }

        $ticket = $this->blobs->issueReadDownload($this->container(), $path, $expiresInSeconds);

        return [
            'url' => $ticket->uploadUrl,
            'httpMethod' => $ticket->httpMethod,
            'requiredHeaders' => $ticket->requiredHeaders,
            'expiresAtUtc' => $ticket->expiresAtUtc,
        ];
    }

    public function objectExists(string $blobPath): bool
    {
        return $this->blobs->headObject($this->container(), ltrim($blobPath, '/')) !== null;
    }

    public function deleteIfManaged(ExpenseAttachmentRef $ref): void
    {
        $path = $this->managedBlobPath($ref);
        if ($path === null) {
            return;
        }
        $this->blobs->deleteObject($this->container(), $path);
    }

    public function container(): string
    {
        $configured = trim($this->settings->blobContainerTriexpense);
        return $configured !== '' ? $configured : Module::BLOB_CONTAINER;
    }

    private function managedBlobPath(ExpenseAttachmentRef $ref): ?string
    {
        if ($ref->isAzureBlobPath()) {
            return ltrim($ref->reference(), '/');
        }

        if ($ref->storageSource() !== ExpenseAttachmentRef::SOURCE_AZURE_BLOB) {
            return null;
        }

        $url = $ref->reference();
        $account = trim($this->settings->blobAccountName);
        $container = $this->container();
        $prefix = $account !== ''
            ? sprintf('https://%s.blob.core.windows.net/%s/', $account, $container)
            : '';
        if ($prefix !== '' && str_starts_with($url, $prefix)) {
            return rawurldecode(ltrim(substr($url, strlen($prefix)), '/'));
        }

        $generic = '/blob.core.windows.net/' . $container . '/';
        $pos = stripos($url, $generic);
        if ($pos !== false) {
            return rawurldecode(ltrim(substr($url, $pos + strlen($generic)), '/'));
        }

        return null;
    }
}
