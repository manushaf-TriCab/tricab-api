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
use TriCab\Core\Product\Application\Command\ActivateProduct360SetCommand;
use TriCab\Core\Product\Application\Service\ActivateProduct360SetService;

final class ActivateProduct360SetAction
{
    public function __construct(
        private readonly ActivateProduct360SetService $activate,
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
            $setPublicId = RouteParams::require($request, 'setPublicId');
            $actor = AdminActorFactory::fromRequest($request);

            $activated = $this->transaction->run(fn () => $this->activate->execute(
                $actor,
                new ActivateProduct360SetCommand(setPublicId: $setPublicId),
            ));

            return $this->responder->success(ProductManagementDtoSerializer::product360SetSummary($activated, $this->devUrls));
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
