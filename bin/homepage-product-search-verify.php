<?php

declare(strict_types=1);

/**
 * Homepage product search live verification (read-only).
 * Usage: php bin/homepage-product-search-verify.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use TriCab\Api\Bootstrap\CoreAutoload;

CoreAutoload::register();

use Dotenv\Dotenv;
use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Core\Product\Application\DTO\ProductSearchCriteria;
use TriCab\Core\Product\Application\Service\SearchProductsService;

$root = dirname(__DIR__);
Dotenv::createImmutable($root)->safeLoad();
$settings = Environment::settings($root);

if ($settings->isProduction()) {
    fwrite(STDERR, "PRODUCTION_BLOCKED\n");
    exit(4);
}

$container = ContainerFactory::create($settings);
/** @var SearchProductsService $search */
$search = $container->get(SearchProductsService::class);

$queries = ['BV', 'DV', 'marine', 'power cable', 'BVA', 'Flexible'];
$report = ['queries' => [], 'decision' => 'HOMEPAGE_SEARCH_PENDING'];

foreach ($queries as $query) {
    $result = $search->execute(new ProductSearchCriteria(
        query: $query,
        page: 1,
        pageSize: 6,
        regionCode: 'AU',
        publishedOnly: true,
    ));

    $items = [];
    foreach ($result->items as $item) {
        $items[] = [
            'productCode' => $item->productCode,
            'productName' => $item->productName,
            'statusCode' => $item->statusCode,
            'slug' => $item->slug,
            'publicId' => $item->publicId,
            'categoryName' => $item->categoryName,
        ];
    }

    $report['queries'][$query] = [
        'totalItems' => $result->pagination->totalItems,
        'items' => $items,
    ];
}

$hitQueries = 0;
foreach ($report['queries'] as $payload) {
    if (($payload['totalItems'] ?? 0) > 0) {
        ++$hitQueries;
    }
}

if ($hitQueries >= 2) {
    $report['decision'] = 'HOMEPAGE_SEARCH_READY';
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
