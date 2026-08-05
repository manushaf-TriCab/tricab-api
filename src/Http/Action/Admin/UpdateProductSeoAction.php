<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\AdminActorFactory;
use TriCab\Api\Http\Auth\AdminMutationTransaction;
use TriCab\Api\Http\Auth\ProductManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\Product\UpdateSeoRequest;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Request\JsonRequestBody;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Product\Application\Command\UpdateProductSeoCommand;
use TriCab\Core\Product\Application\Service\UpdateProductSeoService;

final class UpdateProductSeoAction
{
    public function __construct(
        private readonly UpdateProductSeoService $updateSeo,
        private readonly AdminMutationTransaction $transaction,
        private readonly JsonResponder $responder,
        private readonly ProductManagementErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $productPublicId = RouteParams::require($request, 'productPublicId');
            $input = UpdateSeoRequest::fromArray(JsonRequestBody::requireObject($request));
            $actor = AdminActorFactory::fromRequest($request);

            $this->transaction->run(fn () => $this->updateSeo->execute(
                $actor,
                new UpdateProductSeoCommand(
                    productPublicId: $productPublicId,
                    regionPublicId: $input->regionPublicId,
                    pageTitle: $input->pageTitle,
                    metaDescription: $input->metaDescription,
                    canonicalUrl: $input->canonicalUrl,
                    openGraphTitle: $input->openGraphTitle,
                    openGraphDescription: $input->openGraphDescription,
                    openGraphImageUrl: $input->openGraphImageUrl,
                    searchKeywords: $input->searchKeywords,
                ),
            ));

            return $this->responder->success(ProductManagementDtoSerializer::acknowledged());
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
