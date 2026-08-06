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
use TriCab\Core\Product\Application\Command\DeleteProductMediaFileCommand;
use TriCab\Core\Product\Application\Service\DeleteProductMediaFileService;

final class DeleteProductMediaFileAction
{
    public function __construct(
        private readonly DeleteProductMediaFileService $deleteFile,
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
            $actor = AdminActorFactory::fromRequest($request);

            $this->transaction->run(fn () => $this->deleteFile->execute(
                $actor,
                new DeleteProductMediaFileCommand(mediaPublicId: $mediaPublicId),
            ));

            return $this->responder->success(['deleted' => true]);
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
