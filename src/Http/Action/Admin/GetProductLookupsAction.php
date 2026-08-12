<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\ProductManagementErrorMapper;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Infrastructure\Cache\ReferenceDataCache;
use TriCab\Api\Infrastructure\Observability\RequestPerformanceMetrics;
use TriCab\Core\Product\Application\Service\GetProductLookupsService;

final class GetProductLookupsAction
{
    public function __construct(
        private readonly GetProductLookupsService $lookups,
        private readonly JsonResponder $responder,
        private readonly ProductManagementErrorMapper $errors,
        private readonly ReferenceDataCache $cache,
        private readonly RequestPerformanceMetrics $metrics,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($request, $response);

        try {
            $cached = $this->cache->getLookups();
            if (is_array($cached)) {
                $this->metrics->recordLookupsCacheHit(true);
                /** @var array<string, mixed> $cached */
                return $this->responder->success($cached, 200, [
                    'Cache-Control' => 'private, max-age=0, must-revalidate',
                ]);
            }

            $this->metrics->recordLookupsCacheHit(false);
            $payload = ProductManagementDtoSerializer::lookupsBundle($this->lookups->execute());
            $this->cache->setLookups($payload);

            return $this->responder->success($payload, 200, [
                'Cache-Control' => 'private, max-age=0, must-revalidate',
            ]);
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
