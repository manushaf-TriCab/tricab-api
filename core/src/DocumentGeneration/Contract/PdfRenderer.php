<?php

declare(strict_types=1);

namespace TriCab\Core\DocumentGeneration\Contract;

use TriCab\Core\DocumentGeneration\Legacy\LegacyStream;

interface PdfRenderer
{
    public function render(LegacyStream $stream): string;
}
