<?php

declare(strict_types=1);

namespace TriCab\Api\QuoteOrder\DocumentGeneration;

use TriCab\Api\DocumentGeneration\GeneratedPdf;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteOrderActor;
use TriCab\Core\QuoteOrder\Application\Service\GetQuoteService;
use TriCab\DocGen\DocGen;

/**
 * Loads a quote (same auth as GET /quotes/{id}) and renders it as a
 * tricab/docgen quotation.
 */
final readonly class GenerateQuoteTemplatePdfService
{
    public function __construct(
        private GetQuoteService $getQuote,
        private QuoteDetailsToDocGenQuoteMapper $mapper,
        private DocGen $docgen,
    ) {
    }

    public function execute(QuoteOrderActor $actor, string $quoteId): GeneratedPdf
    {
        $quote = $this->getQuote->execute($actor, $quoteId);

        return new GeneratedPdf(
            contents: $this->docgen->render($this->mapper->map($quote)),
            filename: sprintf('Quotation-%s.pdf', $quote->quoteId !== '' ? $quote->quoteId : $quote->id),
            templateName: 'tcqu.ocr',
            warnings: [],
        );
    }
}
