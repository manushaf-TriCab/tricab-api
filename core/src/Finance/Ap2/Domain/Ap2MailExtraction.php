<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Domain;

use TriCab\Core\Finance\Ap2\Ai\Ap2ExtractedInvoice;

/**
 * A suggestion: the invoice fields the extractor read from one attachment of a mail during the sync, for the supplier
 * the mail had then. Nothing is created from it; `create-invoice` reuses it only while the mail's attachment and
 * supplier are still the ones it was read for.
 */
final readonly class Ap2MailExtraction
{
    public function __construct(
        public string $attachmentId,
        public string $fileName,
        public string $supplierId,
        public string $supplierName,
        public Ap2ExtractedInvoice $read,
    ) {
    }

    /** @return array{attachmentId: string, fileName: string, supplierId: string, supplierName: string, read: array<string, mixed>} */
    public function toArray(): array
    {
        return [
            'attachmentId' => $this->attachmentId,
            'fileName' => $this->fileName,
            'supplierId' => $this->supplierId,
            'supplierName' => $this->supplierName,
            'read' => $this->read->toArray(),
        ];
    }

    /** @param array<array-key, mixed> $data the output of {@see toArray()}; null when it is not one */
    public static function fromArray(array $data): ?self
    {
        $read = $data['read'] ?? null;
        if (!is_string($data['attachmentId'] ?? null) || !is_string($data['fileName'] ?? null) || !is_string($data['supplierId'] ?? null) || !is_string($data['supplierName'] ?? null) || !is_array($read)) {
            return null;
        }

        return new self($data['attachmentId'], $data['fileName'], $data['supplierId'], $data['supplierName'], Ap2ExtractedInvoice::fromArray($read));
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    public static function fromJson(?string $json): ?self
    {
        if ($json === null || trim($json) === '') {
            return null;
        }
        $data = json_decode($json, true);

        return is_array($data) ? self::fromArray($data) : null;
    }
}
