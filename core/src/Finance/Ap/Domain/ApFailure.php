<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Domain;

use RuntimeException;

/**
 * A refusal a Tri-AP use case wrote to be shown to a person: the source's own message, with
 * the status the platform answers (the source's 400 is a 422, as in Tri-Task). Anything that
 * is not an `ApFailure` is left to the platform's own 500 handling.
 *
 * `$extra` carries what the source put beside the message in its error body, if anything.
 */
abstract class ApFailure extends RuntimeException
{
    /**
     * @param array<string, mixed> $extra
     */
    public function __construct(string $message, public readonly array $extra = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    abstract public function httpStatus(): int;

    abstract public function errorCode(): string;
}
