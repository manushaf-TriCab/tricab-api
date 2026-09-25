<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\PdfCatalogue;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\PdfCatalogue\Application\Service\ListPdfCataloguesService;

final class ListPdfCataloguesAction
{
    public function __construct(
        private readonly ListPdfCataloguesService $listCatalogues,
        private readonly JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($request, $response);

        try {
            $result = $this->listCatalogues->execute();

            return $this->responder->success(PdfCatalogueDtoSerializer::list($result->items));
        } catch (Throwable $exception) {
            error_log('[pdf-catalogue] list unexpected: ' . $exception->getMessage());

            return $this->responder->error(
                'CATALOGUE_UNAVAILABLE',
                'Unable to load catalogues right now.',
                503,
            );
        }
    }
}
