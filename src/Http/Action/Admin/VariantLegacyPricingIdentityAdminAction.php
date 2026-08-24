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
use TriCab\Core\Product\Application\Command\AuditLegacyVariantPricingIdentityCommand;
use TriCab\Core\Product\Application\Command\ResolveLegacyVariantPricingIdentityCommand;
use TriCab\Core\Product\Application\Command\SetVariantLegacyPricingIdentityCommand;
use TriCab\Core\Product\Application\Command\VerifyVariantLegacyPricingIdentityCommand;
use TriCab\Core\Product\Application\Service\AuditLegacyVariantPricingIdentityService;
use TriCab\Core\Product\Application\Service\ManageVariantLegacyPricingIdentityService;
use TriCab\Core\Product\Application\Service\ResolveLegacyVariantPricingIdentityService;

final class VariantLegacyPricingIdentityAdminAction
{
    public function __construct(
        private readonly ResolveLegacyVariantPricingIdentityService $resolver,
        private readonly ManageVariantLegacyPricingIdentityService $manage,
        private readonly AuditLegacyVariantPricingIdentityService $audit,
        private readonly AdminMutationTransaction $transaction,
        private readonly JsonResponder $responder,
        private readonly ProductManagementErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);
        try {
            $method = strtoupper($request->getMethod());
            $path = $request->getUri()->getPath();

            if (str_contains($path, '/legacy-identity-audit') && $method === 'POST') {
                return $this->auditProduct($request);
            }
            if (str_contains($path, '/legacy-identities') && $method === 'GET') {
                return $this->listProduct($request);
            }
            if (str_contains($path, '/legacy-identity/verify') && $method === 'POST') {
                return $this->verify($request);
            }
            if (str_contains($path, '/legacy-identity') && $method === 'GET') {
                return $this->resolveVariant($request);
            }
            if (str_contains($path, '/legacy-identity') && $method === 'PUT') {
                return $this->set($request);
            }
            if (str_contains($path, '/legacy-identity') && $method === 'DELETE') {
                return $this->remove($request);
            }

            throw new \InvalidArgumentException('Unsupported legacy pricing identity operation.');
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }

    private function listProduct(ServerRequestInterface $request): ResponseInterface
    {
        $productPublicId = RouteParams::require($request, 'productPublicId');
        $priceListPublicId = $this->requirePriceListPublicId($request);
        $items = $this->audit->listForProduct($productPublicId, $priceListPublicId);

        return $this->responder->success([
            'items' => array_map(ProductManagementDtoSerializer::legacyVariantPricingIdentity(...), $items),
        ]);
    }

    private function auditProduct(ServerRequestInterface $request): ResponseInterface
    {
        $productPublicId = RouteParams::require($request, 'productPublicId');
        $body = JsonRequestBody::optionalObject($request) ?? [];
        $priceListPublicId = is_string($body['priceListPublicId'] ?? null) ? $body['priceListPublicId'] : '';
        if ($priceListPublicId === '') {
            $priceListPublicId = $this->requirePriceListPublicId($request);
        }
        $offset = is_numeric($body['offset'] ?? null) ? (int) $body['offset'] : 0;
        $limit = is_numeric($body['limit'] ?? null) ? (int) $body['limit'] : 100;

        $summary = $this->audit->audit(new AuditLegacyVariantPricingIdentityCommand(
            productPublicId: $productPublicId,
            priceListPublicId: $priceListPublicId,
            offset: $offset,
            limit: $limit,
        ));

        return $this->responder->success(ProductManagementDtoSerializer::legacyVariantPricingIdentityAudit($summary));
    }

    private function resolveVariant(ServerRequestInterface $request): ResponseInterface
    {
        $variantPublicId = RouteParams::require($request, 'variantPublicId');
        $priceListPublicId = $this->requirePriceListPublicId($request);
        $resolution = $this->resolver->execute(new ResolveLegacyVariantPricingIdentityCommand(
            variantPublicId: $variantPublicId,
            priceListPublicId: $priceListPublicId,
        ));

        return $this->responder->success(ProductManagementDtoSerializer::legacyVariantPricingIdentity($resolution));
    }

    private function verify(ServerRequestInterface $request): ResponseInterface
    {
        $variantPublicId = RouteParams::require($request, 'variantPublicId');
        $body = JsonRequestBody::requireObject($request);
        $legacyPriceCode = is_string($body['legacyPriceCode'] ?? null) ? trim($body['legacyPriceCode']) : '';
        $priceListPublicId = is_string($body['priceListPublicId'] ?? null) ? $body['priceListPublicId'] : '';
        if ($legacyPriceCode === '' || $priceListPublicId === '') {
            throw new \InvalidArgumentException('legacyPriceCode and priceListPublicId are required.');
        }

        $inspection = $this->manage->verify(new VerifyVariantLegacyPricingIdentityCommand(
            variantPublicId: $variantPublicId,
            legacyPriceCode: $legacyPriceCode,
            priceListPublicId: $priceListPublicId,
        ));

        return $this->responder->success(ProductManagementDtoSerializer::legacyPricingCodeInspection($inspection));
    }

    private function set(ServerRequestInterface $request): ResponseInterface
    {
        $variantPublicId = RouteParams::require($request, 'variantPublicId');
        $body = JsonRequestBody::requireObject($request);
        $legacyPriceCode = is_string($body['legacyPriceCode'] ?? null) ? trim($body['legacyPriceCode']) : '';
        $priceListPublicId = is_string($body['priceListPublicId'] ?? null) ? $body['priceListPublicId'] : '';
        if ($legacyPriceCode === '' || $priceListPublicId === '') {
            throw new \InvalidArgumentException('legacyPriceCode and priceListPublicId are required.');
        }

        $actor = AdminActorFactory::fromRequest($request);
        $saved = $this->transaction->run(fn () => $this->manage->set($actor, new SetVariantLegacyPricingIdentityCommand(
            variantPublicId: $variantPublicId,
            legacyPriceCode: $legacyPriceCode,
            priceListPublicId: $priceListPublicId,
        )));

        return $this->responder->success(ProductManagementDtoSerializer::variantLegacyPricingIdentityMapping($saved));
    }

    private function remove(ServerRequestInterface $request): ResponseInterface
    {
        $variantPublicId = RouteParams::require($request, 'variantPublicId');
        $actor = AdminActorFactory::fromRequest($request);
        $this->transaction->run(fn () => $this->manage->remove($actor, $variantPublicId));

        return $this->responder->success(['removed' => true]);
    }

    private function requirePriceListPublicId(ServerRequestInterface $request): string
    {
        $query = $request->getQueryParams();
        $priceListPublicId = is_string($query['priceListPublicId'] ?? null) ? $query['priceListPublicId'] : '';
        if ($priceListPublicId === '') {
            throw new \InvalidArgumentException('priceListPublicId is required.');
        }

        return $priceListPublicId;
    }
}
