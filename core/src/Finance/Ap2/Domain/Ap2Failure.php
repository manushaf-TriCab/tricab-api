<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Domain;

use RuntimeException;
use Throwable;

/**
 * A refusal a Tri-AP use case wrote for a person: a message, a status, a stable code and any extra fields for the
 * envelope (`{success:false, error:{code, message, ...extra}}`). The contract puts the individual reasons at
 * `error.details.issues`, so build a refusal as `new Ap2Conflict('Message', Ap2Failure::details($issues))`.
 */
abstract class Ap2Failure extends RuntimeException
{
    /** @param array<string, mixed> $extra */
    public function __construct(string $message, public readonly array $extra = [], ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    abstract public function httpStatus(): int;

    abstract public function errorCode(): string;

    /**
     * @param list<string> $issues
     * @return array{details: array{issues: list<string>}}
     */
    public static function details(array $issues): array
    {
        return ['details' => ['issues' => $issues]];
    }
}
