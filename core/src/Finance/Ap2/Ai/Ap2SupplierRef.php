<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Ai;

/** A supplier as the AI sees it: enough to recognise a sender, nothing about money or bank details. */
final readonly class Ap2SupplierRef
{
    public function __construct(
        public string $id,
        public string $name,
        /** The supplier's main address, lower case; '' when none. */
        public string $email,
        /** The domain of `email` (`acme.com.au`); '' when none. */
        public string $domain,
    ) {
    }
}
