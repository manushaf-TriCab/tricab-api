<?php

declare(strict_types=1);

namespace TriCab\Api\LegacyMigration\Application\Service;

use TriCab\Api\Config\Settings;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Api\LegacyMigration\Persistence\AzureSqlExistingEntraLinkLookup;
use TriCab\Api\LegacyMigration\Persistence\AzureSqlExistingNormalizedEmailLookup;
use TriCab\Api\LegacyMigration\Persistence\ReadOnlyAuditedSqlConnection;
use TriCab\Api\Product\Migration\ReadOnlyMysqlConnectionFactory;
use TriCab\Core\LegacyMigration\Contract\ExistingEntraLinkLookup;
use TriCab\Core\LegacyMigration\Contract\ExistingNormalizedEmailLookup;
use TriCab\Core\LegacyMigration\Live\LegacyIdentitySourceConfig;
use TriCab\Core\LegacyMigration\Live\LegacyIdentitySourceHealth;
use TriCab\Core\LegacyMigration\Live\LegacyStaffReadOnlyAnalyzer;
use TriCab\Core\LegacyMigration\Live\ReadOnlySqlAudit;
use TriCab\Core\LegacyMigration\Plugin\NullExistingEntraLinkLookup;
use TriCab\Core\LegacyMigration\Plugin\NullExistingNormalizedEmailLookup;
use TriCab\Core\Product\Migration\Live\InMemoryLegacySqlStatementLogger;

/**
 * Builds an ephemeral read-only staff analyzer per request. Never persists analysis or batches.
 */
final class LegacyStaffReadOnlyAnalysisService
{
    /** @var array<string, mixed>|null */
    private ?array $lastResult = null;

    public function __construct(
        private readonly LegacyIdentitySourceConfig $config,
        private readonly ReadOnlyMysqlConnectionFactory $mysqlFactory,
        private readonly LegacyIdentitySourceHealth $health,
        private readonly Settings $settings,
        private readonly ?SqlConnection $azureConnection,
    ) {
    }

    /** @return array<string, mixed> */
    public function analyze(): array
    {
        if (!$this->config->liveExtractionConfigured()) {
            throw new \RuntimeException('Legacy WordPress and TC sources must be configured for staff analysis.');
        }

        $audit = new ReadOnlySqlAudit();
        $logger = new InMemoryLegacySqlStatementLogger();
        $wp = $this->mysqlFactory->create(
            (string) $this->config->legacy->wordpressDsn,
            (string) $this->config->legacy->wordpressUser,
            (string) $this->config->legacy->wordpressPassword,
            'wordpress-staff-analysis',
            $this->config->legacy->wordpressSslMode ?? 'required',
            $logger,
        );
        $tc = $this->mysqlFactory->create(
            (string) $this->config->legacy->tcDsn,
            (string) $this->config->legacy->tcUser,
            (string) $this->config->legacy->tcPassword,
            'tc-staff-analysis',
            $this->config->legacy->tcSslMode ?? 'required',
            $logger,
        );

        [$existingEmails, $existingEntra] = $this->identityLookups($audit);

        $analyzer = new LegacyStaffReadOnlyAnalyzer(
            $wp,
            $tc,
            $this->config,
            $this->health,
            $existingEmails,
            $existingEntra,
            $audit,
            $logger,
        );

        $this->lastResult = $analyzer->analyze();

        return $this->lastResult;
    }

    /** @return array<string, mixed>|null */
    public function lastResult(): ?array
    {
        return $this->lastResult;
    }

    /**
     * @return array{0: ExistingNormalizedEmailLookup, 1: ExistingEntraLinkLookup}
     */
    private function identityLookups(ReadOnlySqlAudit $audit): array
    {
        if (!$this->settings->usesAzureSqlUserRepository() || $this->azureConnection === null) {
            return [new NullExistingNormalizedEmailLookup(), new NullExistingEntraLinkLookup()];
        }

        $audited = new ReadOnlyAuditedSqlConnection($this->azureConnection, $audit);

        return [
            new AzureSqlExistingNormalizedEmailLookup($audited),
            new AzureSqlExistingEntraLinkLookup($audited),
        ];
    }
}
