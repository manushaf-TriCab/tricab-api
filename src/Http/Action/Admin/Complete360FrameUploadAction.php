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
use TriCab\Core\Product\Application\Command\Complete360FrameUploadCommand;
use TriCab\Core\Product\Application\Service\Complete360FrameUploadService;

final class Complete360FrameUploadAction
{
    public function __construct(
        private readonly Complete360FrameUploadService $complete,
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
            $framePublicId = RouteParams::require($request, 'framePublicId');
            $actor = AdminActorFactory::fromRequest($request);

            $completed = $this->transaction->run(fn () => $this->complete->execute(
                $actor,
                new Complete360FrameUploadCommand(framePublicId: $framePublicId),
            ));

            return $this->responder->success(ProductManagementDtoSerializer::product360FrameSummary($completed, $this->devUrls));
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
