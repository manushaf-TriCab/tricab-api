<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Exception;

use RuntimeException;
use TriCab\Core\Product\Application\DTO\ProductClassificationUsage;

final class ProductClassificationInUse extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ProductClassificationUsage $usage,
    ) {
        parent::__construct($message);
    }

    public static function cannotArchive(string $label, ProductClassificationUsage $usage): self
    {
        return new self(
            sprintf('%s cannot be archived because %s', $label, $usage->summaryMessage()),
            $usage,
        );
    }

    public static function cannotDeactivate(string $label, ProductClassificationUsage $usage): self
    {
        return new self(
            sprintf('%s cannot be deactivated while active children exist. %s', $label, $usage->summaryMessage()),
            $usage,
        );
    }
}
