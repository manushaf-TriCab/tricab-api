<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Technical;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Identity\Authentication\AuthenticatedUserAttribute;
use TriCab\Api\Technical\ResolveTechnicalAccessoriesAccessService;
use TriCab\Api\Technical\TechnicalAccessoriesVisibility;
use TriCab\Api\Technical\TechnicalContentRepository;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;

final class ListTechnicalSectionsAction
{
    public function __construct(
        private readonly TechnicalContentRepository $content,
        private readonly ResolveTechnicalAccessoriesAccessService $accessoriesAccess,
        private readonly JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);
        try {
            $payload = $this->content->sections();
            $user = $request->getAttribute(AuthenticatedUserAttribute::KEY);
            if ($user instanceof AuthenticatedUser
                && !$this->accessoriesAccess->allows($user)
            ) {
                $payload = $this->withoutAccessoriesSection($payload);
            } elseif (isset($payload['sections']) && is_array($payload['sections'])) {
                $payload['sections'] = $this->withResolvedAccessoriesPolicy($payload['sections']);
            }

            return $this->responder->success(
                $payload,
                200,
                ['Cache-Control' => 'private, no-cache'],
            );
        } catch (Throwable $exception) {
            return $this->responder->error(
                'TECHNICAL_CONTENT_UNAVAILABLE',
                'Unable to load Technical sections.',
                500,
            );
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function withoutAccessoriesSection(array $payload): array
    {
        $sections = $payload['sections'] ?? null;
        if (!is_array($sections)) {
            return $payload;
        }

        $filtered = [];
        foreach ($sections as $section) {
            if (!is_array($section)) {
                continue;
            }
            if (($section['id'] ?? null) === TechnicalAccessoriesVisibility::SECTION_ID) {
                continue;
            }
            $filtered[] = $section;
        }
        $payload['sections'] = $filtered;

        return $payload;
    }

    /**
     * @param list<mixed> $sections
     * @return list<array<string, mixed>>
     */
    private function withResolvedAccessoriesPolicy(array $sections): array
    {
        $out = [];
        foreach ($sections as $section) {
            if (!is_array($section)) {
                continue;
            }
            if (($section['id'] ?? null) === TechnicalAccessoriesVisibility::SECTION_ID) {
                $section['visibilityPolicy'] = TechnicalAccessoriesVisibility::VISIBILITY_POLICY;
                unset($section['visibilityNote']);
            }
            $out[] = $section;
        }

        return $out;
    }
}
