<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Parser;

use RuntimeException;

/**
 * The parser could not produce a result. Typed, because the remedy differs
 * (plan 3.5: distinguish unavailable, auth and quota from retryable errors):
 *
 * | Code                   | Retryable | Meaning                                        |
 * |------------------------|-----------|------------------------------------------------|
 * | `unavailable`          | yes       | the process is down or restarting              |
 * | `timeout`              | yes       | the deadline passed; enqueue instead           |
 * | `invalid_request`      | no        | PHP sent something the contract refuses — a bug |
 * | `unsupported_format`   | no        | a file type no reader handles; say so          |
 * | `input_too_large`      | no        | over a limit the parser enforces too           |
 * | `provider_unavailable` | yes       | a model provider is down; deterministic result may still stand |
 * | `provider_rejected`    | no        | auth or quota at a provider; an operator fixes it |
 * | `contract_mismatch`    | no        | the two sides disagree on the version          |
 * | `internal_error`       | no        | the parser failed; the correlation id finds it |
 *
 * The message is safe to show a person. It never carries document content,
 * a provider response body or a credential (invariant 11).
 */
final class RfqParserFailure extends RuntimeException
{
    public const UNAVAILABLE = 'unavailable';
    public const TIMEOUT = 'timeout';
    public const INVALID_REQUEST = 'invalid_request';
    public const UNSUPPORTED_FORMAT = 'unsupported_format';
    public const INPUT_TOO_LARGE = 'input_too_large';
    public const PROVIDER_UNAVAILABLE = 'provider_unavailable';
    public const PROVIDER_REJECTED = 'provider_rejected';
    public const CONTRACT_MISMATCH = 'contract_mismatch';
    public const INTERNAL_ERROR = 'internal_error';

    private const RETRYABLE = [self::UNAVAILABLE, self::TIMEOUT, self::PROVIDER_UNAVAILABLE];

    public const CODES = [
        self::UNAVAILABLE,
        self::TIMEOUT,
        self::INVALID_REQUEST,
        self::UNSUPPORTED_FORMAT,
        self::INPUT_TOO_LARGE,
        self::PROVIDER_UNAVAILABLE,
        self::PROVIDER_REJECTED,
        self::CONTRACT_MISMATCH,
        self::INTERNAL_ERROR,
    ];

    private function __construct(
        public readonly string $failureCode,
        string $message,
        public readonly ?string $correlationId,
    ) {
        parent::__construct($message);
    }

    public static function of(string $code, string $message, ?CorrelationId $correlationId = null): self
    {
        if (!in_array($code, self::CODES, true)) {
            $code = self::INTERNAL_ERROR;
        }

        return new self($code, $message, $correlationId?->toString());
    }

    public function isRetryable(): bool
    {
        return in_array($this->failureCode, self::RETRYABLE, true);
    }

    /** The HTTP status the public endpoint answers with. */
    public function httpStatus(): int
    {
        return match ($this->failureCode) {
            self::INPUT_TOO_LARGE => 413,
            self::UNSUPPORTED_FORMAT => 415,
            self::TIMEOUT => 504,
            self::UNAVAILABLE, self::PROVIDER_UNAVAILABLE => 503,
            default => 502,
        };
    }
}
