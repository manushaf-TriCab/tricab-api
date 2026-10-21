<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Domain\Exception;

use DomainException;

/**
 * Raised when an uploaded GLB fails structural inspection. The error code is stable.
 */
final class Product3DGlbInvalid extends DomainException
{
    public const BAD_MAGIC = 'GLB_BAD_MAGIC';
    public const BAD_VERSION = 'GLB_BAD_VERSION';
    public const BAD_LENGTH = 'GLB_BAD_LENGTH';
    public const TOO_LARGE = 'GLB_TOO_LARGE';
    public const BAD_JSON_CHUNK = 'GLB_BAD_JSON_CHUNK';
    public const NO_MESH = 'GLB_NO_MESH';
    public const EXTERNAL_URI = 'GLB_EXTERNAL_URI';
    public const FORBIDDEN_EXTENSION = 'GLB_FORBIDDEN_EXTENSION';

    private function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }

    public static function because(string $errorCode, string $message): self
    {
        return new self($errorCode, $message);
    }
}
