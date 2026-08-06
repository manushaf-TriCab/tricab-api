<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Exception;

use RuntimeException;

final class ProductFileUploadException extends RuntimeException
{
    public static function blobMissing(string $container, string $blobPath): self
    {
        return new self(sprintf('Uploaded blob not found at "%s/%s".', $container, $blobPath));
    }

    public static function blobMismatch(string $reason): self
    {
        return new self(sprintf('Uploaded blob does not match declared metadata: %s.', $reason));
    }

    public static function invalidState(string $message): self
    {
        return new self($message);
    }

    public static function downloadForbidden(string $reason): self
    {
        return new self(sprintf('Document download not allowed: %s.', $reason));
    }

    public static function framesIncomplete(string $setPublicId, int $expected, int $uploaded): self
    {
        return new self(sprintf(
            'Cannot activate 360 set "%s": expected %d frames, uploaded %d.',
            $setPublicId,
            $expected,
            $uploaded,
        ));
    }
}
