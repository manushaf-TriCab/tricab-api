<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Ap;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Ap\Domain\ApFailure;
use TriCab\Core\Finance\Ap\Service\ApInvoiceDownloadService;

/**
 * POST /api/v1/finance/ap/invoices/download (the 64th source route): `{ids: [...]}` answers the one document, or a zip of
 * several, with `Content-Disposition: attachment` (the filing name), `X-Invoices-Without-File` (how many selected invoices had
 * no document) and `Cache-Control: no-store`. An invoice out of the person's mailbox scope is a 404 for the whole request.
 * Gated on `FINANCE.AP.INVOICES.READ`.
 */
final class DownloadApInvoicesAction
{
    use ApActionSupport;

    public function __construct(
        private readonly ApInvoiceDownloadService $service,
        private readonly ApActorResolver $actors,
        private readonly JsonResponder $responder,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        unset($response, $args);
        $actor = $this->actors->actorOf($request);
        if ($actor === null) {
            return $this->unauthenticated($this->responder);
        }
        try {
            $download = $this->service->download($actor, $this->bodyOf($request)['ids'] ?? null);
        } catch (ApFailure $failure) {
            return $this->failure($this->responder, $failure);
        }

        $ascii = preg_replace('/[^\x20-\x7e]/u', '_', str_replace(['\\', '"'], '_', $download->fileName)) ?? 'download';
        $out = (new Response(200))
            ->withHeader('Content-Type', $download->contentType)
            ->withHeader('Content-Length', (string) strlen($download->bytes))
            ->withHeader('Content-Disposition', 'attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($download->fileName))
            ->withHeader('X-Invoices-Without-File', (string) $download->withoutFile)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'no-store');
        $out->getBody()->write($download->bytes);

        return $out;
    }
}
