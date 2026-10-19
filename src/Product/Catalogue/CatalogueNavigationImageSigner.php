<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Catalogue;

use Throwable;
use TriCab\Api\Config\Settings;
use TriCab\Api\Product\Media\StableMediaUrlSigner;
use TriCab\Api\Product\Storage\ProductDevMediaUrlResolver;

/**
 * Signs private product-blob catalogue images at response time.
 *
 * Stored CatalogueNodes.ImageUrl values stay unsigned. Public /assets and
 * /media paths are returned unchanged. Only this account's blob endpoint is
 * signed, including a URL that already carries a read signature. Public
 * responses given a request origin carry stable image addresses instead, which
 * redirect to a fresh signature on every load.
 */
final class CatalogueNavigationImageSigner
{
    private const SAS_SKEW_MARGIN_SECONDS = 30;

    public function __construct(
        private readonly ProductDevMediaUrlResolver $mediaUrls,
        private readonly Settings $settings,
        private readonly StableMediaUrlSigner $stableUrls,
    ) {
    }

    public function present(?string $storedUrl, ?string $origin = null): ?string
    {
        if ($storedUrl === null) {
            return null;
        }
        $trimmed = trim($storedUrl);
        if ($trimmed === '') {
            return null;
        }
        if ($this->isPublicPath($trimmed)) {
            return $trimmed;
        }
        if (preg_match('#^https?://#i', $trimmed) !== 1) {
            return null;
        }
        if (!$this->isOwnBlobUrl($trimmed)) {
            return $trimmed;
        }
        if ($origin !== null) {
            $stable = $this->stableUrls->stableUrlForBlobUrl($trimmed, $origin);
            if ($stable !== null) {
                return $stable;
            }
        }

        try {
            $signed = $this->mediaUrls->resolvePublicUrl($trimmed, '');
        } catch (Throwable) {
            return null;
        }

        if (!is_string($signed) || $signed === '' || !$this->isOwnBlobUrl($signed)) {
            return null;
        }

        return $signed;
    }

    /**
     * Drop a read signature before a catalogue node image is stored.
     */
    public function canonicalStoredUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }
        $trimmed = trim($url);
        if ($trimmed === '') {
            return null;
        }
        $trimmed = $this->stableUrls->blobUrlForStableUrl($trimmed) ?? $trimmed;
        if (!$this->isOwnBlobUrl($trimmed)) {
            return $trimmed;
        }

        $parts = parse_url($trimmed);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $path = $parts['path'] ?? '';
        if ($path === '' || $path === '/') {
            return null;
        }

        return $parts['scheme'] . '://' . $parts['host'] . $path;
    }

    /**
     * Browser and intermediary cache lifetime must end before the read signature.
     */
    public function cacheControl(bool $public): string
    {
        $budget = max(0, $this->settings->blobDownloadSasTtlSeconds - self::SAS_SKEW_MARGIN_SECONDS);
        $maxAge = min(60, intdiv($budget, 3));
        $staleWhileRevalidate = min(120, max(0, $budget - $maxAge));
        $scope = $public ? 'public' : 'private';
        if ($maxAge === 0) {
            return $scope . ', no-store';
        }

        return sprintf('%s, max-age=%d, stale-while-revalidate=%d', $scope, $maxAge, $staleWhileRevalidate);
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    public function signPublicNode(array $node, ?string $origin = null): array
    {
        return $this->signNode($node, true, $origin);
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    public function signAdminNode(array $node): array
    {
        return $this->signNode($node, false);
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    public function signPublicDocument(array $document, ?string $origin = null): array
    {
        if (isset($document['tree']) && is_array($document['tree'])) {
            $document['tree'] = $this->signNodeList($document['tree'], true, $origin);
        }
        if (isset($document['items']) && is_array($document['items'])) {
            $document['items'] = $this->signNodeList($document['items'], true, $origin);
        }
        if (array_key_exists('imageUrl', $document) || array_key_exists('children', $document)) {
            $document = $this->signPublicNode($document, $origin);
        }

        return $document;
    }

    /**
     * @param array<mixed> $nodes
     * @return list<mixed>
     */
    private function signNodeList(array $nodes, bool $includeProducts, ?string $origin = null): array
    {
        $signed = [];
        foreach ($nodes as $node) {
            $signed[] = is_array($node)
                ? $this->signNode($this->stringKeyed($node), $includeProducts, $origin)
                : $node;
        }

        return $signed;
    }

    /**
     * @param array<mixed> $node
     * @return array<string, mixed>
     */
    private function stringKeyed(array $node): array
    {
        $keyed = [];
        foreach ($node as $key => $value) {
            if (is_string($key)) {
                $keyed[$key] = $value;
            }
        }

        return $keyed;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function signNode(array $node, bool $includeProducts, ?string $origin = null): array
    {
        if (array_key_exists('imageUrl', $node)) {
            $node['imageUrl'] = $this->present(is_string($node['imageUrl']) ? $node['imageUrl'] : null, $origin);
        }
        if (array_key_exists('productThumbnailUrl', $node)) {
            $node['productThumbnailUrl'] = $this->present(
                is_string($node['productThumbnailUrl']) ? $node['productThumbnailUrl'] : null,
                $origin,
            );
        }
        if (isset($node['children']) && is_array($node['children'])) {
            $node['children'] = $this->signNodeList($node['children'], $includeProducts, $origin);
        }
        if ($includeProducts && isset($node['products']) && is_array($node['products'])) {
            $products = [];
            foreach ($node['products'] as $product) {
                if (is_array($product) && array_key_exists('thumbnailUrl', $product)) {
                    $product['thumbnailUrl'] = $this->present(
                        is_string($product['thumbnailUrl']) ? $product['thumbnailUrl'] : null,
                        $origin,
                    );
                }
                $products[] = $product;
            }
            $node['products'] = $products;
        }

        return $node;
    }

    private function isPublicPath(string $url): bool
    {
        return str_starts_with($url, '/') && !str_starts_with($url, '//');
    }

    private function isOwnBlobUrl(string $url): bool
    {
        $endpoint = rtrim($this->settings->blobEndpoint, '/');
        if ($endpoint === '') {
            return false;
        }

        return str_starts_with(strtolower($url), strtolower($endpoint) . '/');
    }
}
