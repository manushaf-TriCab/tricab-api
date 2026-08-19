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
use TriCab\Api\Http\Request\JsonRequestBody;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Product\Application\Command\SetProductPricingActiveCommand;
use TriCab\Core\Product\Application\Service\SetProductPricingActiveService;

final class SetProductPricingActiveAction
{
    public function __construct(
        private readonly SetProductPricingActiveService $setActive,
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
            $pricingPublicId = RouteParams::require($request, 'pricingPublicId');
            $path = rtrim($request->getUri()->getPath(), '/');
            $activate = str_ends_with($path, '/activate');
            $body = JsonRequestBody::optionalObject($request) ?? [];
            $expectedRowVersion = is_string($body['expectedRowVersion'] ?? null) ? $body['expectedRowVersion'] : null;
            $actor = AdminActorFactory::fromRequest($request);

            $saved = $this->transaction->run(fn () => $this->setActive->execute(
                $actor,
                new SetProductPricingActiveCommand(
                    productPublicId: $productPublicId,
                    pricingPublicId: $pricingPublicId,
                    isActive: $activate,
                    expectedRowVersion: $expectedRowVersion,
                ),
            ));

            return $this->responder->success(ProductManagementDtoSerializer::pricingSummary($saved));
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
