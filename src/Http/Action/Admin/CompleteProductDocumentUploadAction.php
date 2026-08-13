<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\AdminActorFactory;
use TriCab\Api\Http\Auth\AdminMutationTransaction;
use TriCab\Api\Http\Auth\ProductManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Product\Storage\ProductDevMediaUrlResolver;
use TriCab\Core\Product\Application\Command\CompleteProductDocumentUploadCommand;
use TriCab\Core\Product\Application\Service\CompleteProductDocumentUploadService;

final class CompleteProductDocumentUploadAction
{
    public function __construct(
        private readonly CompleteProductDocumentUploadService $complete,
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
            $documentPublicId = RouteParams::require($request, 'documentPublicId');
            $actor = AdminActorFactory::fromRequest($request);

            $completed = $this->transaction->run(fn () => $this->complete->execute(
                $actor,
                new CompleteProductDocumentUploadCommand(documentPublicId: $documentPublicId),
            ));

            return $this->responder->success(
                ProductManagementDtoSerializer::documentSummary($completed->toSummary(true), true, $this->devUrls),
            );
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
