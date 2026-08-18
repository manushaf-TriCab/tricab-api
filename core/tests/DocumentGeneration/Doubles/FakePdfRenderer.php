<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\DocumentGeneration\Doubles;

use TriCab\Core\DocumentGeneration\Contract\PdfRenderer;
use TriCab\Core\DocumentGeneration\Legacy\LegacyStream;

final class FakePdfRenderer implements PdfRenderer
{
    public ?LegacyStream $lastStream = null;

    public function render(LegacyStream $stream): string
    {
        $this->lastStream = $stream;

        return '%PDF-1.4 fake document';
    }
}
