<?php

declare(strict_types=1);

namespace TriCab\Core\Npr\Domain\Exception;

use RuntimeException;

/** The answers are not complete or valid enough for the step. Maps to 422 with the per-field errors. */
final class NprValidationFailed extends RuntimeException
{
    /** @param array<string, list<string>> $errors */
    public function __construct(string $message, public readonly array $errors)
    {
        parent::__construct($message);
    }
}
