<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Onboarding;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Onboarding\Application\DTO\OnboardingDocumentView;
use TriCab\Core\Onboarding\Application\Service\ListOwnDocumentsService;

/**
 * GET /api/v1/onboarding/documents — the signed-in person's own documents.
 *
 * Metadata only, and no query parameters: no kind filter, no paging cursor, no
 * person. A person's own document list is a handful of items, and a parameter
 * that is not there is a parameter that cannot be turned into a probe.
 *
 * Empty for everybody today. Phase 5 generates documents; this is the way in,
 * built while the table is empty because that is when access control is safe to
 * get wrong.
 */
final class GetOnboardingDocumentsAction
{
    public function __construct(
        private readonly ListOwnDocumentsService $documents,
        private readonly OnboardingSelfServiceResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        return $this->responder->run($request, function (AuthenticatedUser $user): ResponseInterface {
            return $this->responder->success([
                'documents' => array_map(
                    static fn (OnboardingDocumentView $view): array => $view->toArray(),
                    $this->documents->forUser($user->userId),
                ),
            ]);
        });
    }
}
