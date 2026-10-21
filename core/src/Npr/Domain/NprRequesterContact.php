<?php

declare(strict_types=1);

namespace TriCab\Core\Npr\Domain;

/** What the staff directory says about a requester, used to pre-fill a new draft. Null when unknown. */
final class NprRequesterContact
{
    public function __construct(
        public readonly ?string $officeLocation,
        public readonly ?string $phone,
    ) {
    }
}
