<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Exception;

use RuntimeException;

final class AppleAuthorizationChallengeInvalid extends RuntimeException
{
    public const REASON_INVALID = 'invalid';
    public const REASON_EXPIRED = 'expired';
    public const REASON_NONCE_MISMATCH = 'nonce_mismatch';
    public const REASON_WRONG_PROVIDER = 'wrong_provider';

    private function __construct(
        string $message,
        public readonly string $reason = self::REASON_INVALID,
    ) {
        parent::__construct($message);
    }

    public static function create(
        string $message = 'Apple authorization challenge is invalid.',
        string $reason = self::REASON_INVALID,
    ): self {
        return new self($message, $reason);
    }

    public static function expired(): self
    {
        return new self('Apple authorization challenge has expired.', self::REASON_EXPIRED);
    }

    public static function nonceMismatch(): self
    {
        return new self('Apple authorization challenge nonce mismatch.', self::REASON_NONCE_MISMATCH);
    }

    public static function wrongProvider(): self
    {
        return new self('Apple authorization challenge provider mismatch.', self::REASON_WRONG_PROVIDER);
    }
}
