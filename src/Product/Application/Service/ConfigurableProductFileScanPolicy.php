<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Product\Contract\ProductFileScanPolicy;
use TriCab\Core\Product\Domain\ValueObject\ScanStatusCode;

final class ConfigurableProductFileScanPolicy implements ProductFileScanPolicy
{
    public function __construct(
        private readonly bool $autoMarkClean = true,
    ) {
    }

    public function resolveAfterUpload(): ScanStatusCode
    {
        return $this->autoMarkClean
            ? ScanStatusCode::clean()
            : ScanStatusCode::pendingScan();
    }
}
