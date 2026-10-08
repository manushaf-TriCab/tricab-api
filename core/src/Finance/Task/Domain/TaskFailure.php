<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Task\Domain;

use RuntimeException;

/**
 * A refusal a Tri-Task use case wrote to be shown to a person: the portal's
 * `StoreError`. Every message is the source's own words; anything untyped is not
 * a `TaskFailure` and is left to the platform's own 500 handling.
 *
 * `$extra` carries what the source put beside the message in its error body
 * (`blockers`, `count`), which the pages read.
 */
abstract class TaskFailure extends RuntimeException
{
    /**
     * @param array<string, mixed> $extra
     */
    public function __construct(string $message, public readonly array $extra = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /** The HTTP status the source answered with. */
    abstract public function httpStatus(): int;

    abstract public function errorCode(): string;
}
