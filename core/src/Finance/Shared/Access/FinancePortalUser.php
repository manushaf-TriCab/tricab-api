<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Shared\Access;

/** One row of an export of the finance portal's `platform_users`. */
final readonly class FinancePortalUser
{
    public function __construct(
        public string $id,
        /** Lower-case and trimmed. */
        public string $email,
        public string $name,
        public bool $active,
    ) {
    }
}
