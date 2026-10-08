<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Shared\Ai;

use RuntimeException;

/**
 * The AI service could not produce a result. Typed, because the remedy differs:
 *
 * | Code                   | Retryable | Meaning                                              |
 * |------------------------|-----------|------------------------------------------------------|
 * | `unavailable`          | yes       | the process is down, restarting, or not configured    |
 * | `timeout`              | yes       | the deadline passed                                   |
 * | `invalid_request`      | no        | PHP sent something the contract refuses: a bug        |
 * | `unknown_purpose`      | no        | no such purpose is registered in the service          |
 * | `contract_mismatch`    | no        | the two sides disagree on the version                 |
 * | `provider_unavailable` | yes       | the model provider is down or throttling              |
 * | `provider_rejected`    | no        | key, quota or billing at the provider: an operator    |
 * | `bad_model_answer`     | no        | the model's answer did not fit the purpose's schema   |
 * | `internal_error`       | no        | the service failed; the correlation id finds it       |
 *
 * The message is safe to show a person: no data, no prompt, no provider text and
 * no credential. The codes mirror `finance_ai.contract.CODES`.
 */
final class FinanceAiFailure extends RuntimeException
{
    public const UNAVAILABLE = 'unavailable';
    public const TIMEOUT = 'timeout';
    public const INVALID_REQUEST = 'invalid_request';
    public const UNKNOWN_PURPOSE = 'unknown_purpose';
    public const CONTRACT_MISMATCH = 'contract_mismatch';
    public const PROVIDER_UNAVAILABLE = 'provider_unavailable';
    public const PROVIDER_REJECTED = 'provider_rejected';
    public const BAD_MODEL_ANSWER = 'bad_model_answer';
    public const INTERNAL_ERROR = 'internal_error';

    public const CODES = [
        self::UNAVAILABLE,
        self::TIMEOUT,
        self::INVALID_REQUEST,
        self::UNKNOWN_PURPOSE,
        self::CONTRACT_MISMATCH,
        self::PROVIDER_UNAVAILABLE,
        self::PROVIDER_REJECTED,
        self::BAD_MODEL_ANSWER,
        self::INTERNAL_ERROR,
    ];

    private const RETRYABLE = [self::UNAVAILABLE, self::TIMEOUT, self::PROVIDER_UNAVAILABLE];

    private function __construct(
        public readonly string $failureCode,
        string $message,
        public readonly ?string $correlationId,
    ) {
        parent::__construct($message);
    }

    public static function of(string $code, string $message, ?string $correlationId = null): self
    {
        return new self(in_array($code, self::CODES, true) ? $code : self::INTERNAL_ERROR, $message, $correlationId);
    }

    public function isRetryable(): bool
    {
        return in_array($this->failureCode, self::RETRYABLE, true);
    }
}
