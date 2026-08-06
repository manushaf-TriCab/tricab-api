<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Domain\Exception;

use DomainException;

final class InvalidProductFileException extends DomainException
{
    public static function forMimeType(string $mimeType, string $fileKind): self
    {
        return new self(sprintf('MIME type "%s" is not allowed for %s files.', $mimeType, $fileKind));
    }

    public static function forExtension(string $extension, string $fileKind): self
    {
        return new self(sprintf('Extension "%s" is not allowed for %s files.', $extension, $fileKind));
    }

    public static function forSize(int $byteSize, int $maxBytes, string $fileKind): self
    {
        return new self(sprintf(
            'File size %d bytes exceeds the %d byte limit for %s files.',
            $byteSize,
            $maxBytes,
            $fileKind,
        ));
    }

    public static function forSignature(string $reason): self
    {
        return new self(sprintf('File signature validation failed: %s.', $reason));
    }

    public static function forIncompatible(string $extension, string $mimeType): self
    {
        return new self(sprintf(
            'Extension "%s" is incompatible with declared MIME type "%s".',
            $extension,
            $mimeType,
        ));
    }

    public static function for(string $message): self
    {
        return new self($message);
    }
}
