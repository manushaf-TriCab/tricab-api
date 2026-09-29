<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Rfq;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Identity\Authentication\AuthenticatedUserAttribute;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Rfq\Application\DTO\ReaderResult;
use TriCab\Core\Rfq\Application\DTO\SourceDocument;
use TriCab\Core\Rfq\Application\Service\ResolveRfqActorService;
use TriCab\Core\Rfq\Application\Service\RfqReaderService;

/**
 * GET /api/v1/rfqs/{publicId}/reads — the RFQ's documents and their
 * background reads (`rfq.document.v1`, `rfq.reader-result.v1`): what the
 * editor polls while a read is running (parity plan E4.2). Gated on
 * `RFQ.DRAFT.READ`; an RFQ the person cannot see is 404.
 */
final class ListRfqReadsAction
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
        unset($response);

        $user = $request->getAttribute(AuthenticatedUserAttribute::KEY);
        if (!$user instanceof AuthenticatedUser) {
            return $this->responder->error('UNAUTHENTICATED', 'Authentication is required.', 401);
        }
        $actor = $this->actors->forUser($user);
        $publicId = $args['publicId'] ?? '';
        $reads = $this->reader->reads($actor, $publicId);
        $documents = $this->reader->documents($actor, $publicId);
        if ($reads === null || $documents === null) {
            return $this->responder->error('RFQ_NOT_FOUND', 'RFQ not found.', 404);
        }

        return $this->responder->success([
            'documents' => array_map(static fn (SourceDocument $d): array => $d->toArray(), $documents),
            'reads' => array_map(static fn (ReaderResult $r): array => $r->toArray(), $reads),
        ]);
    }
}
