<?php

declare(strict_types=1);

namespace TriCab\Api\LegacyMigration;

use TriCab\Core\LegacyMigration\Contract\LegacySourceHealthProbe;
use TriCab\Core\LegacyMigration\Live\LegacyIdentitySourceConfig;
use TriCab\Core\LegacyMigration\Live\LegacyIdentitySourceHealth;
use TriCab\Core\LegacyMigration\Support\SourceCatalog;
use TriCab\Core\Product\Migration\Live\LegacyReadOnlyConnection;

final class LiveLegacyIdentityHealthProbe implements LegacySourceHealthProbe
{
    public function __construct(
        private readonly LegacyIdentitySourceConfig $config,
        private readonly LegacyIdentitySourceHealth $health,
        private readonly ?LegacyReadOnlyConnection $wordpress,
        private readonly ?LegacyReadOnlyConnection $tc,
        private readonly ?\Throwable $wordpressConnectError = null,
        private readonly ?\Throwable $tcConnectError = null,
    ) {
    }

    public function probe(string $code): ?array
    {
        if ($code === SourceCatalog::WORDPRESS) {
            return $this->inspectWordpress();
        }
        if ($code === SourceCatalog::BUSINESS_DB) {
            return $this->inspectTc();
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function inspectWordpress(): array
    {
        if (!$this->config->legacy->wordpressConfigured() || ($this->wordpress === null && $this->wordpressConnectError === null)) {
            return $this->health->blocked(
                SourceCatalog::WORDPRESS,
                'WordPress legacy source is not configured.',
                false,
                $this->config->wordpressReadOnly,
                $this->config->developmentOverride,
            );
        }
        if ($this->wordpressConnectError !== null || $this->wordpress === null) {
            return $this->health->blocked(
                SourceCatalog::WORDPRESS,
                'WordPress connection failed. Extraction has been blocked. ' . self::safeError($this->wordpressConnectError ?? new \RuntimeException('WordPress connection was not established.')),
                true,
                $this->config->wordpressReadOnly,
                $this->config->developmentOverride,
            );
        }
        try {
            return $this->health->inspect(
                $this->wordpress,
                SourceCatalog::WORDPRESS,
                $this->config->expectedWordpressDatabase,
                $this->config->wordpressReadOnly,
                (string) ($this->config->legacy->wordpressSslMode ?? 'required'),
                $this->config->developmentOverride,
            );
        } catch (\Throwable $e) {
            return $this->health->blocked(
                SourceCatalog::WORDPRESS,
                'WordPress connection failed. Extraction has been blocked. ' . self::safeError($e),
                true,
                $this->config->wordpressReadOnly,
                $this->config->developmentOverride,
            );
        }
    }

    /** @return array<string, mixed> */
    private function inspectTc(): array
    {
        if (!$this->config->legacy->tcConfigured() || ($this->tc === null && $this->tcConnectError === null)) {
            return $this->health->blocked(
                SourceCatalog::BUSINESS_DB,
                'Business database (tc) legacy source is not configured.',
                false,
                $this->config->tcReadOnly,
                $this->config->developmentOverride,
            );
        }
        if ($this->tcConnectError !== null || $this->tc === null) {
            return $this->health->blocked(
                SourceCatalog::BUSINESS_DB,
                'Business database connection failed. Extraction has been blocked. ' . self::safeError($this->tcConnectError ?? new \RuntimeException('Business database connection was not established.')),
                true,
                $this->config->tcReadOnly,
                $this->config->developmentOverride,
            );
        }
        try {
            return $this->health->inspect(
                $this->tc,
                SourceCatalog::BUSINESS_DB,
                $this->config->expectedTcDatabase,
                $this->config->tcReadOnly,
                (string) ($this->config->legacy->tcSslMode ?? 'required'),
                $this->config->developmentOverride,
            );
        } catch (\Throwable $e) {
            return $this->health->blocked(
                SourceCatalog::BUSINESS_DB,
                'Business database connection failed. Extraction has been blocked. ' . self::safeError($e),
                true,
                $this->config->tcReadOnly,
                $this->config->developmentOverride,
            );
        }
    }

    private static function safeError(\Throwable $e): string
    {
        $message = $e->getMessage();
        $message = preg_replace('/password=[^;\s]*/i', 'password=***', $message) ?? $message;
        $message = preg_replace('/PWD=[^;\s]*/i', 'PWD=***', $message) ?? $message;
        $message = preg_replace('/AccessToken\s*=\s*[^;]+/i', 'AccessToken=***', $message) ?? $message;

        return $message;
    }
}
