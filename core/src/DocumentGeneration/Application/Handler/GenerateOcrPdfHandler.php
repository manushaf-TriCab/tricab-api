<?php

declare(strict_types=1);

namespace TriCab\Core\DocumentGeneration\Application\Handler;

use TriCab\Core\DocumentGeneration\Application\DTO\GeneratedPdf;
use TriCab\Core\DocumentGeneration\Application\Exception\InvalidOcrStream;
use TriCab\Core\DocumentGeneration\Application\Exception\UnsupportedDocumentTemplate;
use TriCab\Core\DocumentGeneration\Contract\PdfRenderer;
use TriCab\Core\DocumentGeneration\Legacy\LegacyStreamParser;

final readonly class GenerateOcrPdfHandler
{
    public function __construct(
        private LegacyStreamParser $parser,
        private PdfRenderer $renderer,
    ) {
    }

    /**
     * @throws InvalidOcrStream
     * @throws UnsupportedDocumentTemplate
     */
    public function handle(string $ocrContents): GeneratedPdf
    {
        if ($ocrContents === '') {
            throw new InvalidOcrStream('The OCR stream is empty.');
        }

        $stream = $this->parser->parse($ocrContents, allowTruncatedFinalRecord: true);
        $templateName = $stream->templateName();
        $filename = match ($templateName) {
            'tcord.ocr' => 'order-confirmation.pdf',
            'tcpick.ocr' => 'pick-list.pdf',
            'tcqu.ocr' => 'quotation.pdf',
            default => throw UnsupportedDocumentTemplate::forTemplate($templateName),
        };

        return new GeneratedPdf(
            contents: $this->renderer->render($stream),
            filename: $filename,
            templateName: $templateName,
            warnings: $stream->warnings,
        );
    }
}
