<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Application\DTO;

final readonly class CustomerContactReportStoredFile
{
    public function __construct(
        public string $blobPath,
        public string $url,
    ) {
    }
}
