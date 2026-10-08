<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Ar;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Ar\Domain\ArFailure;
use TriCab\Core\Finance\Ar\Service\ArAttachmentService;

/**
 * GET /api/v1/finance/ar/records/{id}/attachments/{aid}/file (source route 9): one kept file, a customer's payment
 * document. Gated on `FINANCE.AR.ATTACHMENTS.READ` (Q5) and then on the record guard.
 *
 * The file is untrusted. It is served with `nosniff` and a policy that allows nothing and sandboxes the document, so
 * a script a PDF carries cannot run in the app's origin; `?inline=1` shows it in the browser, otherwise it downloads.
 */
final class GetArAttachmentFileAction
{
    use ArActionSupport;

    public function __construct(
        private readonly ArAttachmentService $service,
        private readonly JsonResponder $responder,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        unset($response);
        $actor = $this->actorOf($request);
        if ($actor === null) {
            return $this->unauthenticated($this->responder);
        }
        try {
            $file = $this->service->file($actor, (string) ($args['id'] ?? ''), (string) ($args['aid'] ?? ''));
        } catch (ArFailure $failure) {
            return $this->failure($this->responder, $failure);
        }

        $name = str_replace(['\\', '"'], '_', $file->downloadName);
        $ascii = preg_replace('/[^\x20-\x7e]/', '_', $name) ?? 'file';
        $disposition = ($this->queryOf($request)['inline'] ?? null) === '1' ? 'inline' : 'attachment';
        $out = (new Response(200))
            ->withHeader('Content-Type', $file->contentType)
            ->withHeader('Content-Length', (string) strlen($file->bytes))
            ->withHeader('Content-Disposition', $disposition . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name))
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Security-Policy', "default-src 'none'; sandbox")
            ->withHeader('Cache-Control', 'private, no-store');
        $out->getBody()->write($file->bytes);

        return $out;
    }
}
