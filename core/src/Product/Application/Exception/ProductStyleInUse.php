<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Exception;

use RuntimeException;
use TriCab\Core\Product\Application\DTO\ProductStyleUsage;

final class ProductStyleInUse extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ProductStyleUsage $usage,
    ) {
        parent::__construct($message);
    }

    public static function cannotArchive(ProductStyleUsage $usage): self
    {
        return new self(
            sprintf('Style cannot be archived because %s', $usage->summaryMessage()),
            $usage,
        );
    }

    public static function cannotDeactivate(ProductStyleUsage $usage): self
    {
        return new self(
            sprintf('Style cannot be deactivated while active children exist. %s', $usage->summaryMessage()),
            $usage,
        );
    }
}
