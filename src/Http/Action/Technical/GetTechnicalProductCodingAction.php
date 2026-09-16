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
use TriCab\Api\Technical\TechnicalContentRepository;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;

final class GetTechnicalProductCodingAction
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
        $mode = (string) $request->getAttribute('mode', '');

        if (strcasecmp($mode, TechnicalAccessoriesVisibility::PRODUCT_CODING_MODE) === 0) {
            $user = $request->getAttribute(AuthenticatedUserAttribute::KEY);
            if (!$user instanceof AuthenticatedUser || !$this->accessoriesAccess->allows($user)) {
                return $this->responder->error(
                    'TECHNICAL_PRODUCT_CODING_NOT_FOUND',
                    'Technical product coding not found.',
                    404,
                );
            }
        }

        try {
            return $this->responder->success(
                $this->content->productCoding($mode),
                200,
                ['Cache-Control' => 'private, no-cache'],
            );
        } catch (RuntimeException $exception) {
            $message = $exception->getMessage();
            if (str_contains($message, 'Invalid Technical content key') || str_contains($message, 'missing')) {
                return $this->responder->error('TECHNICAL_PRODUCT_CODING_NOT_FOUND', 'Technical product coding not found.', 404);
            }

            return $this->responder->error('TECHNICAL_CONTENT_UNAVAILABLE', 'Unable to load Technical product coding.', 500);
        } catch (Throwable $exception) {
            return $this->responder->error('TECHNICAL_CONTENT_UNAVAILABLE', 'Unable to load Technical product coding.', 500);
        }
    }
}
