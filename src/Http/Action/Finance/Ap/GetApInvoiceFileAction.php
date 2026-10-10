<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Ap;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Ap\Domain\ApFailure;
use TriCab\Core\Finance\Ap\Service\ApInvoiceService;

/**
 * GET /api/v1/finance/ap/invoices/{id}/file (replaces source route 46, question Q7): the invoice's document, found by invoice id
 * and the person's mailbox scope, never by a stored name. Gated on `FINANCE.AP.INVOICES.READ`.
 *
 * The file is untrusted. It is served with `nosniff` and a policy that allows nothing and sandboxes the document, so a script a
 * PDF carries cannot run in the app's origin. `?inline=1` shows a PDF, PNG or JPEG in the browser; any other file, and any
 * other query, downloads.
 */
final class GetApInvoiceFileAction
{
    use ApActionSupport;

    public function __construct(
        private readonly ApInvoiceService $service,
        private readonly ApActorResolver $actors,
        private readonly JsonResponder $responder,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        unset($response);
        $actor = $this->actors->actorOf($request);
        if ($actor === null) {
            return $this->unauthenticated($this->responder);
        }
        try {
            $file = $this->service->document($actor, (string) ($args['id'] ?? ''));
        } catch (ApFailure $failure) {
            return $this->failure($this->responder, $failure);
        }

        $ascii = preg_replace('/[^\x20-\x7e]/u', '_', str_replace(['\\', '"'], '_', $file->downloadName)) ?? 'file';
        $kind = ($this->queryOf($request)['inline'] ?? null) === '1' && $file->inlineAllowed ? 'inline' : 'attachment';
        $out = (new Response(200))
            ->withHeader('Content-Type', $file->contentType)
            ->withHeader('Content-Length', (string) strlen($file->bytes))
            ->withHeader('Content-Disposition', $kind . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($file->downloadName))
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Security-Policy', "default-src 'none'; sandbox")
            ->withHeader('Cache-Control', 'private, no-store');
        $out->getBody()->write($file->bytes);

        return $out;
    }
}
