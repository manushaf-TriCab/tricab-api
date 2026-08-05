<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\AdminActorFactory;
use TriCab\Api\Http\Auth\AdminMutationTransaction;
use TriCab\Api\Http\Auth\ProductManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\Product\ExpectedRowVersionRequest;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Request\JsonRequestBody;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Product\Application\Command\ArchiveProductCommand;
use TriCab\Core\Product\Application\Service\ArchiveProductService;

final class ArchiveProductAction
{
    public function __construct(
        private readonly ArchiveProductService $archiveProduct,
        private readonly AdminMutationTransaction $transaction,
        private readonly JsonResponder $responder,
        private readonly ProductManagementErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $publicId = RouteParams::require($request, 'productPublicId');
            $input = ExpectedRowVersionRequest::fromArray(JsonRequestBody::requireObject($request));
            $actor = AdminActorFactory::fromRequest($request);

            $result = $this->transaction->run(fn () => $this->archiveProduct->execute(
                $actor,
                new ArchiveProductCommand($publicId, $input->expectedRowVersion),
            ));

            return $this->responder->success(ProductManagementDtoSerializer::mutationResult($result));
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
