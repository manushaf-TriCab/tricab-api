<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Onboarding;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Auth\AdminActorFactory;
use TriCab\Core\Onboarding\Application\Service\FormPhotoService;

/**
 * POST /api/v1/admin/onboarding/queue/{assignmentPublicId}/photos (5.4).
 * Body: `{ "photo": "<base64 JPEG>" }`. Answers `{ "documentPublicId": "…" }`,
 * which the form's photo field then names.
 */
final class PostOnboardingFormPhotoAction
{
    public function __construct(
        private readonly FormPhotoService $photos,
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
            $id = $this->photos->uploadAsHr(
                AdminActorFactory::fromRequest($request),
                $arguments['assignmentPublicId'] ?? '',
                OnboardingSelfServiceResponder::body($request)['photo'] ?? null,
            );

            return $this->responder->success(['documentPublicId' => $id], 201);
        });
    }
}
