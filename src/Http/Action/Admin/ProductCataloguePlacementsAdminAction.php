<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\AdminActorFactory;
use TriCab\Api\Http\Auth\AdminMutationTransaction;
use TriCab\Api\Http\Auth\ProductManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\Product\ReplaceProductCataloguePlacementsRequest;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Request\JsonRequestBody;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Product\Application\Command\ReplaceProductCataloguePlacementsCommand;
use TriCab\Core\Product\Application\Service\ManageCatalogueNavigationService;

final class ProductCataloguePlacementsAdminAction
{
    public function __construct(
        private readonly ManageCatalogueNavigationService $service,
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
            $method = strtoupper($request->getMethod());

            if ($method === 'GET') {
                return $this->responder->success([
                    'items' => CatalogueDtoSerializer::productPlacementList(
                        $this->service->listProductPlacements($productPublicId),
                    ),
                ]);
            }

            $input = ReplaceProductCataloguePlacementsRequest::fromArray(JsonRequestBody::requireObject($request));
            $actor = AdminActorFactory::fromRequest($request);
            $this->transaction->run(fn () => $this->service->replaceProductPlacements(
                $actor,
                new ReplaceProductCataloguePlacementsCommand($productPublicId, $input->placements),
            ));

            return $this->responder->success(['acknowledged' => true]);
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
