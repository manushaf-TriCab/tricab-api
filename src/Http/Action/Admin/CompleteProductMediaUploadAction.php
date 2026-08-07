<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\AdminActorFactory;
use TriCab\Api\Http\Auth\AdminMutationTransaction;
use TriCab\Api\Http\Auth\ProductManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\Product\CompleteProductMediaUploadRequest;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Product\Storage\ProductDevMediaUrlResolver;
use TriCab\Core\Product\Application\Command\CompleteProductMediaUploadCommand;
use TriCab\Core\Product\Application\Service\CompleteProductMediaUploadService;

final class CompleteProductMediaUploadAction
{
    public function __construct(
        private readonly CompleteProductMediaUploadService $complete,
        private readonly ProductDevMediaUrlResolver $devUrls,
        private readonly AdminMutationTransaction $transaction,
        private readonly JsonResponder $responder,
        private readonly ProductManagementErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $mediaPublicId = RouteParams::require($request, 'mediaPublicId');
            $parsed = $request->getParsedBody();
            $body = is_array($parsed) ? $parsed : [];
            /** @var array<string, mixed> $body */
            $input = CompleteProductMediaUploadRequest::fromArray($body);
            $actor = AdminActorFactory::fromRequest($request);

            $completed = $this->transaction->run(fn () => $this->complete->execute(
                $actor,
                new CompleteProductMediaUploadCommand(
                    mediaPublicId: $mediaPublicId,
                    binaryContent: $input->binaryContent,
                ),
            ));

            return $this->responder->success(
                ProductManagementDtoSerializer::mediaFileRecord($completed, $this->devUrls),
            );
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
