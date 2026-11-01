<?php

declare(strict_types=1);

namespace TriCab\Api\CableDrawing;

use RuntimeException;

/** Why a product code can't be drawn; the code maps to the HTTP status. */
final class CableDrawingException extends RuntimeException
{
    public const INVALID_CODE = 'CABLE_CODE_INVALID';
    public const SPEC_NOT_FOUND = 'CABLE_SPEC_NOT_FOUND';
    public const UNAVAILABLE = 'CABLE_DRAWING_UNAVAILABLE';

    private function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }

    public static function invalidCode(string $code): self
    {
        return new self(self::INVALID_CODE, sprintf('"%s" is not a cable product code (expected e.g. CA-PAXN/5C16BK).', $code));
    }

    public static function specNotFound(string $code): self
    {
        return new self(self::SPEC_NOT_FOUND, sprintf('No cable specification matches %s.', $code));
    }

    public static function unavailable(string $code, string $reason): self
    {
        return new self(self::UNAVAILABLE, sprintf('%s can\'t be drawn: %s', $code, $reason));
    }

    public function httpStatus(): int
    {
        return match ($this->errorCode) {
            self::INVALID_CODE => 400,
            self::SPEC_NOT_FOUND => 404,
            default => 422,
        };
    }
}
