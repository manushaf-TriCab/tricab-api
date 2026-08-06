<?php

declare(strict_types=1);

namespace TriCab\Core\DocumentGeneration\Application\Exception;

use RuntimeException;

final class UnsupportedDocumentTemplate extends RuntimeException
{
    public static function forTemplate(string $templateName): self
    {
        return new self(sprintf(
            'Unsupported document template "%s". Expected tcord.ocr, tcpick.ocr, or tcqu.ocr.',
            $templateName,
        ));
    }
}
