<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Onboarding;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Core\Onboarding\Application\DTO\OnboardingDocumentView;
use TriCab\Core\Onboarding\Application\Service\PersonDocumentsService;

/**
 * GET /api/v1/admin/onboarding/people/{personPublicId}/documents (5.4).
 *
 * One person's documents for HR, metadata only — the same shape as the
 * self-service list, so the bytes are only ever reached through the audited
 * content route beside it.
 */
final class GetOnboardingPersonDocumentsAction
{
    public function __construct(
        private readonly PersonDocumentsService $documents,
        private readonly OnboardingSelfServiceResponder $responder,
    ) {
    }

    /**
     * @param array<string, string> $arguments
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $arguments = []): ResponseInterface
    {
        unset($response);

        return $this->responder->run($request, function () use ($arguments): ResponseInterface {
            return $this->responder->success([
                'documents' => array_map(
                    static fn (OnboardingDocumentView $view): array => $view->toArray(),
                    $this->documents->list($arguments['personPublicId'] ?? ''),
                ),
            ]);
        });
    }
}
