<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Domain;

use TriCab\Core\Finance\Ap\Domain\ApFailure;

/**
 * A refusal a Tri-AP2 use case wrote for a person. Same envelope as Tri-AP's ({@see ApFailure}); the contract puts
 * the individual reasons at `error.details.issues`, so build a refusal as
 * `new Ap2Conflict('Message', Ap2Failure::details($issues))`.
 */
abstract class Ap2Failure extends ApFailure
{
    /**
     * @param list<string> $issues
     * @return array{details: array{issues: list<string>}}
     */
    public static function details(array $issues): array
    {
        return ['details' => ['issues' => $issues]];
    }
}
