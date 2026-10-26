<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Service;

use TriCab\Core\Finance\Ap2\Contract\Ap2PersonDirectory;
use TriCab\Core\Finance\Ap2\Domain\Ap2Failure;
use TriCab\Core\Finance\Ap2\Domain\Ap2Invalid;
use TriCab\Core\Finance\Module;

/** `GET /users?permission=`: who holds an AP2 permission, for the owner and approver pickers. */
final class Ap2UserService
{
    public function __construct(private readonly Ap2PersonDirectory $people)
    {
    }

    /** @return list<array{userId: int, name: string, email: string}> */
    public function holding(string $short): array
    {
        $short = strtoupper(trim($short));
        $code = 'FINANCE.AP2.' . $short;
        if ($short === '' || !in_array($code, [Module::PERMISSION_AP2_ACCESS, ...Module::AP2_PERMISSIONS], true)) {
            throw new Ap2Invalid('Unknown permission', Ap2Failure::details(['permission must be a Tri-AP2 permission such as INVOICES.WRITE']));
        }

        return array_map(static fn ($p): array => $p->toArray(), $this->people->holding($code));
    }
}
