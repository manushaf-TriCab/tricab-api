<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Onboarding;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use TriCab\Api\Http\Auth\AdminActorFactory;
use TriCab\Core\Onboarding\Application\Service\FormPhotoService;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingDocumentNotFound;
use TriCab\Core\Product\Contract\ProductBlobStore;

/**
 * GET /api/v1/admin/onboarding/queue/{assignmentPublicId}/photos/{photoPublicId} (5.4).
 *
 * Streamed, never a signed URL, and the read is audited before the bytes go
 * (owner decision, 24 Sep 2026). `inline` so the review page can show it; it
 * is fetched with the reviewer's token and turned into an object URL, so it
 * never has an address of its own. Not cached.
 */
final class GetOnboardingFormPhotoAction
{
    public function __construct(
        private readonly FormPhotoService $photos,
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
            $photo = $this->photos->openAsHr(
                AdminActorFactory::fromRequest($request),
                $arguments['assignmentPublicId'] ?? '',
                $arguments['photoPublicId'] ?? '',
            );
            $bytes = $this->blobs->getObjectBinary($photo->blobContainer, $photo->blobPath)
                ?? throw OnboardingDocumentNotFound::forThisPerson();

            $out = new Response(200);
            $out->getBody()->write($bytes);

            return $out
                ->withHeader('Content-Type', 'image/jpeg')
                ->withHeader('Content-Disposition', $photo->contentDisposition('inline'))
                ->withHeader('Cache-Control', 'no-store, private')
                ->withHeader('X-Content-Type-Options', 'nosniff');
        });
    }
}
