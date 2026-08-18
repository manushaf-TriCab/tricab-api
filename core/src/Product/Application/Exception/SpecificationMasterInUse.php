<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Exception;

use RuntimeException;
use TriCab\Core\Product\Application\DTO\SpecificationUsage;

final class SpecificationMasterInUse extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly SpecificationUsage $usage,
    ) {
        parent::__construct($message);
    }

    public static function cannotArchive(string $label, SpecificationUsage $usage): self
    {
        return new self(
            sprintf('%s cannot be archived because %s', $label, $usage->summaryMessage()),
            $usage,
        );
    }
}
