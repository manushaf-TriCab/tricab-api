<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap;

use TriCab\Core\Finance\Ap\Contract\ApSigningLimitStore;
use TriCab\Core\Finance\Ap\Domain\ApSigningLimit;

/** The signing limits held in memory: the default where no database is configured, and the tests'. */
final class InMemoryApSigningLimitStore implements ApSigningLimitStore
{
    /** @var list<ApSigningLimit> */
    private array $rows = [];

    public function add(ApSigningLimit $limit): void
    {
        $this->rows[] = $limit;
    }

    public function all(): array
    {
        $rows = $this->rows;
        usort($rows, static fn (ApSigningLimit $a, ApSigningLimit $b): int => [mb_strtolower($a->fullName), mb_strtolower($a->d365Position)] <=> [mb_strtolower($b->fullName), mb_strtolower($b->d365Position)]);

        return $rows;
    }
}
