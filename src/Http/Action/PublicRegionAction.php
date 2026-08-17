<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Config\Settings;
use TriCab\Api\Http\Auth\ProductManagementErrorMapper;
use TriCab\Api\Http\Request\JsonRequestBody;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Infrastructure\Cache\ReferenceDataCache;
use TriCab\Api\Product\Region\RegionPreferenceResolver;

final class PublicRegionAction
{
    public function __construct(
        private readonly RegionPreferenceResolver $preference,
        private readonly ReferenceDataCache $cache,
        private readonly Settings $settings,
        private readonly JsonResponder $responder,
        private readonly ProductManagementErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);
        try {
            $path = rtrim($request->getUri()->getPath(), '/');
            if (str_ends_with($path, '/current')) {
                return $this->current($request);
            }
            if (str_ends_with($path, '/select')) {
                return $this->select($request);
            }
            $cached = $this->cache->getRegions();
            if (is_array($cached)) {
                return $this->responder->success(['items' => $cached], 200, ['Cache-Control' => 'public, max-age=300']);
            }
            $items = array_map(RegionDtoSerializer::publicRecord(...), $this->preference->publicRegions());
            $this->cache->setRegions($items);
            return $this->responder->success(['items' => $items], 200, ['Cache-Control' => 'public, max-age=300']);
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }

    private function current(ServerRequestInterface $request): ResponseInterface
    {
        $resolved = $this->preference->resolve($request);
        return $this->responder->success([
            'region' => RegionDtoSerializer::publicRecord($resolved['region']),
            'source' => $resolved['source'] === 'explicit' ? 'cookie' : $resolved['source'],
        ]);
    }

    private function select(ServerRequestInterface $request): ResponseInterface
    {
        $body = JsonRequestBody::optionalObject($request) ?? [];
        $value = $body['region'] ?? null;
        if (!is_string($value) || trim($value) === '') {
            $query = $request->getQueryParams();
            $value = $query['region'] ?? $query['rgn'] ?? $query['regionCode'] ?? null;
        }
        if (!is_string($value) || trim($value) === '') {
            throw \TriCab\Api\Http\Exception\HttpRequestValidationFailed::invalid('region is required.');
        }
        $region = $this->preference->requireSelectable($value);
        return $this->responder->success(
            ['region' => RegionDtoSerializer::publicRecord($region), 'source' => 'cookie'],
            200,
            ['Set-Cookie' => $this->cookie($request, $region->code)],
        );
    }

    private function cookie(ServerRequestInterface $request, string $code): string
    {
        $https = strtolower($request->getUri()->getScheme()) === 'https'
            || strtolower(trim(explode(',', $request->getHeaderLine('X-Forwarded-Proto'))[0])) === 'https'
            || $this->settings->isProduction();
        return RegionPreferenceResolver::COOKIE_NAME . '=' . rawurlencode($code)
            . '; Max-Age=31536000; Path=/; SameSite=Lax'
            . ($https ? '; Secure' : '');
    }
}
