<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Ap;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Ap\Domain\ApFailure;
use TriCab\Core\Finance\Ap\Service\ApMessageService;

/**
 * GET /api/v1/finance/ap/emails/{id}/attachments/{aid}/file (source route 37): one attachment, read live from the mailbox.
 * Gated on `FINANCE.AP.MAIL.READ`.
 *
 * The file is whatever a sender attached, so it is served as the invoice file route serves a document: `nosniff`, a policy
 * that allows nothing and sandboxes the document, and no caching. `?inline=1` shows it in the browser only for a PDF, PNG,
 * JPEG, GIF, WebP or plain text; anything else (an HTML or SVG attachment included), and any other query, is an
 * `application/octet-stream` download.
 */
final class GetApEmailAttachmentFileAction
{
    use ApActionSupport;

    public function __construct(
        private readonly ApMessageService $service,
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
            $file = $this->service->file($actor, (string) ($args['id'] ?? ''), (string) ($args['aid'] ?? ''));
        } catch (ApFailure $failure) {
            return $this->failure($this->responder, $failure);
        }

        $inline = ($this->queryOf($request)['inline'] ?? null) === '1' && $file->inlineAllowed;
        $ascii = preg_replace('/[^\x20-\x7e]/u', '_', str_replace(['\\', '"'], '_', $file->downloadName)) ?? 'attachment';
        $out = (new Response(200))
            ->withHeader('Content-Type', $inline ? $file->contentType : 'application/octet-stream')
            ->withHeader('Content-Length', (string) strlen($file->bytes))
            ->withHeader('Content-Disposition', ($inline ? 'inline' : 'attachment') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($file->downloadName))
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Security-Policy', "default-src 'none'; sandbox")
            ->withHeader('Cache-Control', 'private, no-store');
        $out->getBody()->write($file->bytes);

        return $out;
    }
}
