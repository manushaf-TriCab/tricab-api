<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Throwable;
use TriCab\Api\Assistant\Application\ProductAssistantService;
use TriCab\Api\Http\Auth\AssistantCatalogueVisibilityResolver;
use TriCab\Api\Http\Auth\AssistantErrorMapper;
use TriCab\Api\Http\Request\AssistantMessageRequest;
use TriCab\Api\Http\Request\JsonRequestBody;
use TriCab\Api\Http\Response\JsonResponder;

final class AssistantMessageAction
{
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly JsonResponder $responder,
        private readonly AssistantErrorMapper $errors,
        private readonly AssistantCatalogueVisibilityResolver $catalogueVisibility,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $assistant = $this->container->get(ProductAssistantService::class);
            if (!$assistant instanceof ProductAssistantService) {
                throw new RuntimeException('Product assistant service is unavailable.');
            }
            $input = AssistantMessageRequest::fromArray(JsonRequestBody::requireObject($request));
            $visibility = $this->catalogueVisibility->resolve($request);

            return $this->responder->success($assistant->reply(
                message: $input->message,
                searchQuery: $input->searchQuery,
                regionCode: $input->regionCode,
                conversationId: $input->conversationId,
                catalogueVisibility: $visibility,
            ));
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
