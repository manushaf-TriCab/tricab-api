<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Domain\ValueObject;

use TriCab\Core\Expense\Domain\Exception\InvalidExpenseValue;

final class ExpenseAttachmentRef
{
    public const SOURCE_AZURE_BLOB = 'azure_blob';
    public const SOURCE_WORDPRESS = 'wordpress';
    public const SOURCE_UNKNOWN = 'unknown';

    private function __construct(
        private readonly string $storageSource,
        private readonly string $reference,
        private readonly ?string $fileName,
    ) {
    }

    public static function fromLegacyUrl(string $fileUrl, ?string $fileName = null): self
    {
        $url = trim($fileUrl);
        if ($url === '') {
            throw InvalidExpenseValue::for('ExpenseAttachmentRef', $fileUrl);
        }

        $source = self::SOURCE_UNKNOWN;
        if (str_contains($url, 'blob.core.windows.net')) {
            $source = self::SOURCE_AZURE_BLOB;
        } elseif (str_contains($url, '/wp-content/') || str_contains($url, '/expense/')) {
            $source = self::SOURCE_WORDPRESS;
        }

        $name = $fileName !== null ? trim($fileName) : null;
        if ($name === '') {
            $name = null;
        }

        return new self($source, $url, $name);
    }

    public static function reconstitute(string $storageSource, string $reference, ?string $fileName = null): self
    {
        $source = trim($storageSource);
        $ref = trim($reference);
        if ($source === '' || $ref === '') {
            throw InvalidExpenseValue::for('ExpenseAttachmentRef', $storageSource . '|' . $reference);
        }

        $name = $fileName !== null ? trim($fileName) : null;
        if ($name === '') {
            $name = null;
        }

        return new self($source, $ref, $name);
    }

    public function storageSource(): string
    {
        return $this->storageSource;
    }

    public function reference(): string
    {
        return $this->reference;
    }

    public function fileName(): ?string
    {
        return $this->fileName;
    }
}