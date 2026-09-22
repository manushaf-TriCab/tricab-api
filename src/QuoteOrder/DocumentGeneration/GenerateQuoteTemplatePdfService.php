<?php

declare(strict_types=1);

namespace TriCab\Api\QuoteOrder\DocumentGeneration;

use TriCab\Core\DocumentGeneration\Application\DTO\GeneratedPdf;
use TriCab\Core\DocumentGeneration\Contract\PdfRenderer;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteOrderActor;
use TriCab\Core\QuoteOrder\Application\Service\GetQuoteService;

/**
 * Loads a quote (same auth as GET /quotes/{id}) and renders
 * resources/document-generation/templates/quote.php via DompdfPdfRenderer.
 */
final readonly class GenerateQuoteTemplatePdfService
{
    public function __construct(
        private GetQuoteService $getQuote,
        private QuoteDetailsToTcquLegacyStreamMapper $mapper,
        private PdfRenderer $pdfRenderer,
    ) {
    }

    public function execute(QuoteOrderActor $actor, string $quoteId): GeneratedPdf
    {
        $quote = $this->getQuote->execute($actor, $quoteId);
        $stream = $this->mapper->map($quote);
        $contents = $this->pdfRenderer->render($stream);

        return new GeneratedPdf(
            contents: $contents,
            filename: sprintf('Quotation-%s.pdf', $quote->quoteId !== '' ? $quote->quoteId : $quote->id),
            templateName: 'tcqu.ocr',
            warnings: $stream->warnings,
        );
    }
}
