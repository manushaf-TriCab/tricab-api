<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Onboarding;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use TriCab\Api\Http\Auth\AdminActorFactory;
use TriCab\Core\Onboarding\Application\Service\PersonDocumentsService;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingDocumentNotFound;
use TriCab\Core\Product\Contract\ProductBlobStore;

/**
 * GET /api/v1/admin/onboarding/people/{personPublicId}/documents/{documentPublicId}/content (5.4).
 *
 * Always streamed as an attachment, whatever the class, and the read is
 * audited before the bytes are sought — see {@see PersonDocumentsService}.
 * Not cached, for the reason the self-service route gives.
 */
final class GetOnboardingPersonDocumentContentAction
{
    public function __construct(
        private readonly PersonDocumentsService $documents,
        private readonly ProductBlobStore $blobs,
        private readonly OnboardingSelfServiceResponder $responder,
    ) {
    }

    /**
     * @param array<string, string> $arguments
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $arguments = []): ResponseInterface
    {
        unset($response);

        return $this->responder->run($request, function () use ($request, $arguments): ResponseInterface {
            $document = $this->documents->open(
                AdminActorFactory::fromRequest($request),
                $arguments['personPublicId'] ?? '',
                $arguments['documentPublicId'] ?? '',
            );
            $bytes = $this->blobs->getObjectBinary($document->blobContainer, $document->blobPath)
                ?? throw OnboardingDocumentNotFound::forThisPerson();

            $out = new Response(200);
            $out->getBody()->write($bytes);

            return $out
                ->withHeader('Content-Type', $document->contentType !== '' ? $document->contentType : 'application/octet-stream')
                ->withHeader('Content-Disposition', $document->contentDisposition())
                ->withHeader('Cache-Control', 'no-store, private')
                ->withHeader('X-Content-Type-Options', 'nosniff');
        });
    }
}
