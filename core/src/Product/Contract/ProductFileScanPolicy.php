<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Contract;

use TriCab\Core\Product\Domain\ValueObject\ScanStatusCode;

interface ProductFileScanPolicy
{
    public function resolveAfterUpload(): ScanStatusCode;
}
