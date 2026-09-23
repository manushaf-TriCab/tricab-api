<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\PdfCatalogue;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\PdfCatalogue\Application\Exception\PdfCatalogueNotFound;
use TriCab\Core\PdfCatalogue\Application\Service\GetPdfCatalogueService;

final class GetPdfCatalogueAction
{
    public function __construct(
        private readonly GetPdfCatalogueService $getCatalogue,
        private readonly JsonResponder $responder,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args = [],
    ): ResponseInterface {
        unset($request, $response);
        $publicId = trim((string) ($args['publicId'] ?? ''));

        try {
            $item = $this->getCatalogue->execute($publicId);

            return $this->responder->success(PdfCatalogueDtoSerializer::item($item));
        } catch (PdfCatalogueNotFound) {
            return $this->responder->error('CATALOGUE_NOT_FOUND', 'That catalogue could not be found.', 404);
        } catch (Throwable $exception) {
            error_log('[pdf-catalogue] get unexpected: ' . $exception->getMessage());

            return $this->responder->error(
                'CATALOGUE_UNAVAILABLE',
                'Unable to load that catalogue right now.',
                503,
            );
        }
    }
}
