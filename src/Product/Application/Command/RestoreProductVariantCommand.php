<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Command;

/**
 * $fromDeletion selects which restore path to run:
 *  - false (default): restore from archived business status back to draft.
 *  - true: clear soft-delete markers (does not change business status).
 */
final readonly class RestoreProductVariantCommand
{
    public function __construct(
        public string $publicId,
        public string $expectedRowVersion,
        public bool $fromDeletion = false,
    ) {
    }
}
