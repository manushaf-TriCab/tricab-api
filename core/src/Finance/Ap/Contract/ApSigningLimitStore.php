<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Contract;

use TriCab\Core\Finance\Ap\Domain\ApSigningLimit;

/** `invoice_signing_limits`. Read only: it has no editor (question Q12). */
interface ApSigningLimitStore
{
    /**
     * Every position, active or not, by name then position.
     *
     * @return list<ApSigningLimit>
     */
    public function all(): array;
}
