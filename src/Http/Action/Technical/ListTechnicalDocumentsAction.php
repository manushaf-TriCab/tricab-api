<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Technical;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Throwable;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Identity\Authentication\AuthenticatedUserAttribute;
use TriCab\Api\Technical\ResolveTechnicalAccessoriesAccessService;
use TriCab\Api\Technical\TechnicalAccessoriesVisibility;
use TriCab\Api\Technical\Document\ResolveTechnicalDocumentsQuery;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;

final class ListTechnicalDocumentsAction
{
    public function __construct(
        private readonly ResolveTechnicalDocumentsQuery $documentsQuery,
        private readonly ResolveTechnicalAccessoriesAccessService $accessoriesAccess,
        private readonly JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);
        $params = $request->getQueryParams();
        $category = isset($params['category']) && is_string($params['category'])
            ? $params['category']
            : null;

        $user = $request->getAttribute(AuthenticatedUserAttribute::KEY);
        $allowsAccessories = $user instanceof AuthenticatedUser && $this->accessoriesAccess->allows($user);

        if ($category !== null
            && TechnicalAccessoriesVisibility::isAccessoryDocumentCategory($category)
            && !$allowsAccessories
        ) {
            return $this->responder->error(
                'TECHNICAL_DOCUMENTS_NOT_FOUND',
                'Technical documents category not found.',
                404,
            );
        }

        try {
            $payload = $this->documentsQuery->execute($category);
            if (!$allowsAccessories && $category === null) {
                $payload = $this->withoutAccessoryFamilies($payload);
            }

            return $this->responder->success(
                $payload,
                200,
                ['Cache-Control' => 'private, no-cache'],
            );
        } catch (RuntimeException $exception) {
            $message = $exception->getMessage();
            if (str_contains($message, 'missing') || str_contains($message, 'Invalid Technical content key')) {
                return $this->responder->error('TECHNICAL_DOCUMENTS_NOT_FOUND', 'Technical documents category not found.', 404);
            }

            return $this->responder->error('TECHNICAL_CONTENT_UNAVAILABLE', 'Unable to load Technical documents.', 500);
        } catch (Throwable $exception) {
            return $this->responder->error('TECHNICAL_CONTENT_UNAVAILABLE', 'Unable to load Technical documents.', 500);
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function withoutAccessoryFamilies(array $payload): array
    {
        $families = $payload['families'] ?? null;
        if (!is_array($families)) {
            return $payload;
        }

        $filtered = [];
        foreach ($families as $key => $family) {
            if (!is_array($family)) {
                continue;
            }
            $section = strtolower((string) ($family['section'] ?? ''));
            $documentCategory = strtolower((string) ($family['documentCategory'] ?? $key));
            if ($section === TechnicalAccessoriesVisibility::SECTION_ID
                || TechnicalAccessoriesVisibility::isAccessoryDocumentCategory($documentCategory)
                || (is_string($key) && TechnicalAccessoriesVisibility::isAccessoryDocumentCategory($key))
            ) {
                continue;
            }
            $filtered[$key] = $family;
        }
        $payload['families'] = $filtered;

        return $payload;
    }
}
