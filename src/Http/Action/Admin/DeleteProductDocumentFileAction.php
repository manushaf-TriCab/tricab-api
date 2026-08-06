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
use TriCab\Core\Product\Application\Command\DeleteProductDocumentFileCommand;
use TriCab\Core\Product\Application\Service\DeleteProductDocumentFileService;

final class DeleteProductDocumentFileAction
{
    public function __construct(
        private readonly DeleteProductDocumentFileService $deleteFile,
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

            $this->transaction->run(fn () => $this->deleteFile->execute(
                $actor,
                new DeleteProductDocumentFileCommand(documentPublicId: $documentPublicId),
            ));

            return $this->responder->success(['deleted' => true]);
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
