<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\QuoteOrder;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\QuoteOrder\DocumentGeneration\GenerateQuoteTemplatePdfService;
use TriCab\Api\Http\Auth\QuoteOrderErrorMapper;
use TriCab\Api\Http\Auth\ResolveQuoteOrderActorService;

final class GetQuoteTemplateDocumentAction
{
    public function __construct(
        private readonly GenerateQuoteTemplatePdfService $service,
        private readonly ResolveQuoteOrderActorService $actors,
        private readonly QuoteOrderErrorMapper $errors,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        try {
            $actor = $this->actors->fromRequest($request);
            $result = $this->service->execute($actor, (string) ($args['id'] ?? ''));

            $response->getBody()->write($result->contents);

            return $response
                ->withHeader('Content-Type', 'application/pdf')
                ->withHeader(
                    'Content-Disposition',
                    sprintf('inline; filename="%s"', $result->filename),
                )
                ->withHeader('Content-Length', (string) strlen($result->contents))
                ->withHeader('Cache-Control', 'no-store, private')
                ->withHeader('X-Content-Type-Options', 'nosniff')
                ->withHeader('X-OCR-Template', $result->templateName)
                ->withHeader('X-OCR-Warning-Count', (string) count($result->warnings));
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
