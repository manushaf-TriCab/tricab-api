<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Migration;

use TriCab\Core\Product\Migration\Contract\LegacyTcRepository;
use TriCab\Core\Product\Migration\Contract\LegacyWordPressRepository;
use TriCab\Core\Product\Migration\Live\LiveInventoryProbe;
use TriCab\Core\Product\Migration\Live\LiveLegacySourceConfig;

final class MysqlLiveInventoryProbe implements LiveInventoryProbe
{
    public function __construct(
        private readonly ?LegacyWordPressRepository $wordpress,
        private readonly ?LegacyTcRepository $tc,
    ) {
    }

    public function probe(LiveLegacySourceConfig $config): array
    {
        $wordpress = [
            'configured' => $config->wordpressConfigured() ? 1 : 0,
            'waived' => $config->waiveWordpress ? 1 : 0,
        ];
        $tc = [
            'configured' => $config->tcConfigured() ? 1 : 0,
            'waived' => $config->waiveTc ? 1 : 0,
        ];
        $files = [
            'configured' => $config->filesConfigured() ? 1 : 0,
            'waived' => $config->waiveFiles ? 1 : 0,
        ];

        $captured = false;
        if ($this->wordpress !== null && $config->wordpressConfigured()) {
            $schema = $this->wordpress->inspectSchema();
            $wordpress['productCounts'] = $schema['productCounts'] ?? [];
            $wordpress['tables'] = $schema['tables'] ?? [];
            $captured = true;
        }
        if ($this->tc !== null && $config->tcConfigured()) {
            $schema = $this->tc->inspectSchema();
            $tc['rowCounts'] = $schema['rowCounts'] ?? [];
            $tc['tables'] = $schema['tables'] ?? [];
            $captured = true;
        }

        if (!$captured) {
            return [
                'status' => 'PENDING_LIVE_INVENTORY',
                'message' => 'Live WordPress/tc credentials not configured; inventory not captured.',
                'wordpress' => $wordpress,
                'tc' => $tc,
                'files' => $files,
            ];
        }

        return [
            'status' => 'CAPTURED',
            'message' => 'Live inventory captured via read-only SELECT/SHOW/DESCRIBE.',
            'wordpress' => $wordpress,
            'tc' => $tc,
            'files' => $files,
        ];
    }
}
