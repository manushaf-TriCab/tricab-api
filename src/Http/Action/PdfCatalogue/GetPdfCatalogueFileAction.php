<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\PdfCatalogue;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\PdfCatalogue\Application\Exception\PdfCatalogueNotFound;
use TriCab\Core\PdfCatalogue\Application\Exception\PdfCatalogueUnavailable;
use TriCab\Core\PdfCatalogue\Application\Service\GetPdfCatalogueFileService;

final class GetPdfCatalogueFileAction
{
    public function __construct(
        private readonly GetPdfCatalogueFileService $getFile,
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
            return $this->responder->success($this->getFile->execute($publicId));
        } catch (PdfCatalogueNotFound) {
            return $this->responder->error('CATALOGUE_NOT_FOUND', 'That catalogue could not be found.', 404);
        } catch (PdfCatalogueUnavailable $exception) {
            $message = str_contains(strtolower($exception->getMessage()), 'storage')
                ? 'Catalogue document storage is temporarily unavailable.'
                : 'That catalogue document is not available yet.';

            return $this->responder->error('CATALOGUE_DOCUMENT_UNAVAILABLE', $message, 404);
        } catch (Throwable $exception) {
            error_log('[pdf-catalogue] file unexpected: ' . $exception->getMessage());

            return $this->responder->error(
                'CATALOGUE_DOCUMENT_UNAVAILABLE',
                'Unable to open that catalogue document right now.',
                503,
            );
        }
    }
}
