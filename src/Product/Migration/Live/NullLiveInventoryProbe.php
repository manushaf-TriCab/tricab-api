<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Live;

/**
 * Default probe when live credentials are absent. Does not invent counts.
 */
final class NullLiveInventoryProbe implements LiveInventoryProbe
{
    public function probe(LiveLegacySourceConfig $config): array
    {
        return [
            'status' => 'PENDING_LIVE_INVENTORY',
            'message' => 'Live WordPress/tc credentials not configured; inventory not captured.',
            'wordpress' => [
                'configured' => $config->wordpressConfigured() ? 1 : 0,
                'waived' => $config->waiveWordpress ? 1 : 0,
            ],
            'tc' => [
                'configured' => $config->tcConfigured() ? 1 : 0,
                'waived' => $config->waiveTc ? 1 : 0,
            ],
            'files' => [
                'configured' => $config->filesConfigured() ? 1 : 0,
                'waived' => $config->waiveFiles ? 1 : 0,
            ],
        ];
    }
}
