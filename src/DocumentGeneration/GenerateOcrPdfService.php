<?php

declare(strict_types=1);

namespace TriCab\Api\DocumentGeneration;

use TriCab\DocGen\DocGen;
use TriCab\DocGen\Exception\InvalidOcrStream;
use TriCab\DocGen\Exception\UnsupportedOcrTemplate;
use TriCab\DocGen\Legacy\OcrDocumentReader;

/**
 * A raw 4js .ocr print stream (tcqu, tcord, tcpick) to a PDF, through tricab/docgen.
 */
final readonly class GenerateOcrPdfService
{
    /** The download names POST /documents/pdf has always used. */
    private const FILENAMES = [
        'tcord.ocr' => 'order-confirmation.pdf',
        'tcpick.ocr' => 'pick-list.pdf',
        'tcqu.ocr' => 'quotation.pdf',
    ];

    public function __construct(
        private OcrDocumentReader $reader,
        private DocGen $docgen,
    ) {
    }

    /**
     * @throws InvalidOcrStream
     * @throws UnsupportedOcrTemplate
     */
    public function handle(string $ocrContents): GeneratedPdf
    {
        $result = $this->reader->read($ocrContents);

        return new GeneratedPdf(
            contents: $this->docgen->render($result->document),
            filename: self::FILENAMES[$result->templateName] ?? $result->document->filename(),
            templateName: $result->templateName,
            warnings: $result->warnings,
        );
    }
}
