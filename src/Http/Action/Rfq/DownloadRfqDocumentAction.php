<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Rfq;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Identity\Authentication\AuthenticatedUserAttribute;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Rfq\Application\Service\ResolveRfqActorService;
use TriCab\Core\Rfq\Application\Service\RfqReaderService;

/**
 * GET /api/v1/rfqs/{publicId}/documents/{documentId} — one uploaded file, for
 * someone who can read the RFQ (plan 2.5: authorized delivery). The bytes are
 * streamed by the API, never a storage URL; the download name is the display
 * name with anything outside a safe set replaced. Gated on `RFQ.DRAFT.READ`.
 */
final class DownloadRfqDocumentAction
{
    public function __construct(
        private readonly ResolveRfqActorService $actors,
        private readonly RfqReaderService $reader,
        private readonly JsonResponder $responder,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        $user = $request->getAttribute(AuthenticatedUserAttribute::KEY);
        if (!$user instanceof AuthenticatedUser) {
            return $this->responder->error('UNAUTHENTICATED', 'Authentication is required.', 401);
        }
        $found = $this->reader->download($this->actors->forUser($user), $args['publicId'] ?? '', $args['documentId'] ?? '');
        if ($found === null) {
            return $this->responder->error('RFQ_NOT_FOUND', 'Document not found.', 404);
        }
        $name = preg_replace('/[^A-Za-z0-9 ._()-]+/', '_', $found['document']->fileName) ?: 'document';
        $response->getBody()->write($found['bytes']);

        return $response
            ->withHeader('Content-Type', 'application/octet-stream')
            ->withHeader('Content-Length', (string) strlen($found['bytes']))
            ->withHeader('Content-Disposition', sprintf('attachment; filename="%s"', $name))
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'private, no-store');
    }
}
