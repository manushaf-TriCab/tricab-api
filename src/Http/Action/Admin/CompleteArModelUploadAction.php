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
use TriCab\Core\Product\Application\Command\CompleteArModelUploadCommand;
use TriCab\Core\Product\Application\Service\CompleteArModelUploadService;

final class CompleteArModelUploadAction
{
    public function __construct(
        private readonly CompleteArModelUploadService $complete,
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
            $arPublicId = RouteParams::require($request, 'arPublicId');
            $actor = AdminActorFactory::fromRequest($request);

            $completed = $this->transaction->run(fn () => $this->complete->execute(
                $actor,
                new CompleteArModelUploadCommand(arPublicId: $arPublicId),
            ));

            return $this->responder->success(ProductManagementDtoSerializer::productArModelSummary($completed, $this->devUrls));
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
