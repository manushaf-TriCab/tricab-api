<?php

declare(strict_types=1);

namespace TriCab\Api\LegacyMigration\Application\Service;

use TriCab\Api\Config\Settings;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Api\LegacyMigration\Persistence\AzureSqlExistingEntraLinkLookup;
use TriCab\Api\LegacyMigration\Persistence\AzureSqlExistingNormalizedEmailLookup;
use TriCab\Api\LegacyMigration\Persistence\AzureSqlExistingStaffUserDetailLookup;
use TriCab\Api\LegacyMigration\Persistence\AzureSqlTriCabRoleCatalogLookup;
use TriCab\Api\LegacyMigration\Persistence\ReadOnlyAuditedSqlConnection;
use TriCab\Api\Product\Migration\ReadOnlyMysqlConnectionFactory;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\LegacyMigration\Contract\ExistingEntraLinkLookup;
use TriCab\Core\LegacyMigration\Contract\ExistingNormalizedEmailLookup;
use TriCab\Core\LegacyMigration\Contract\ExistingStaffUserDetailLookup;
use TriCab\Core\LegacyMigration\Contract\LegacyMigrationStore;
use TriCab\Core\LegacyMigration\Contract\TriCabRoleCatalogLookup;
use TriCab\Core\LegacyMigration\Live\LegacyIdentitySourceConfig;
use TriCab\Core\LegacyMigration\Live\LegacyIdentitySourceHealth;
use TriCab\Core\LegacyMigration\Live\LegacyStaffReadOnlyAnalyzer;
use TriCab\Core\LegacyMigration\Live\ReadOnlySqlAudit;
use TriCab\Core\LegacyMigration\Plugin\NullExistingEntraLinkLookup;
use TriCab\Core\LegacyMigration\Plugin\NullExistingNormalizedEmailLookup;
use TriCab\Core\LegacyMigration\Plugin\NullExistingStaffUserDetailLookup;
use TriCab\Core\LegacyMigration\Plugin\NullTriCabRoleCatalogLookup;
use TriCab\Core\LegacyMigration\StaffMigration\StaffMigrationBatch1Policy;
use TriCab\Core\LegacyMigration\StaffMigration\StaffMigrationCandidateBuilder;
use TriCab\Core\LegacyMigration\StaffMigration\StaffMigrationEligibility;
use TriCab\Core\LegacyMigration\StaffMigration\StaffMigrationExecutionException;
use TriCab\Core\LegacyMigration\StaffMigration\StaffMigrationProposedAction;
use TriCab\Core\LegacyMigration\StaffMigration\StaffMigrationSnapshotFingerprint;
use TriCab\Core\LegacyMigration\StaffMigration\StaffMigrationScope;
use TriCab\Core\LegacyMigration\StaffMigration\StaffUserMigrationCandidate;
use TriCab\Core\LegacyMigration\StaffMigration\StaffUserMigrationDryRun;
use TriCab\Core\LegacyMigration\Support\SourceCatalog;
use TriCab\Core\Product\Migration\Live\InMemoryLegacySqlStatementLogger;
use TriCab\Api\LegacyMigration\Persistence\AzureSqlLegacyStaffUserMappingRepository;
use TriCab\Core\LegacyMigration\StaffMigration\StaffUserMigrationExecutor;
use TriCab\Core\LegacyMigration\Contract\StaffIdentityUserCreator;

/**
 * Shared read-only staff candidate preparation for dry-run and execute.
 */
final class StaffMigrationPreparationService implements \TriCab\Core\LegacyMigration\Contract\StaffMigrationPreparer
{
    public function __construct(
        private readonly LegacyIdentitySourceConfig $config,
        private readonly ReadOnlyMysqlConnectionFactory $mysqlFactory,
        private readonly LegacyIdentitySourceHealth $health,
        private readonly Settings $settings,
        private readonly ?SqlConnection $azureConnection,
    ) {
    }

    /**
     * @return array{
     *   analysis: array<string, mixed>,
     *   candidates: list<StaffUserMigrationCandidate>,
     *   dryRun: array<string, mixed>,
     *   legacyAudit: ReadOnlySqlAudit
     * }
     */
    public function prepare(): array
    {
        if (!$this->config->liveExtractionConfigured()) {
            throw new \RuntimeException('Legacy WordPress and TC sources must be configured.');
        }

        $audit = new ReadOnlySqlAudit();
        $logger = new InMemoryLegacySqlStatementLogger();
        $wp = $this->mysqlFactory->create(
            (string) $this->config->legacy->wordpressDsn,
            (string) $this->config->legacy->wordpressUser,
            (string) $this->config->legacy->wordpressPassword,
            'wordpress-staff-prep',
            $this->config->legacy->wordpressSslMode ?? 'required',
            $logger,
        );
        $tc = $this->mysqlFactory->create(
            (string) $this->config->legacy->tcDsn,
            (string) $this->config->legacy->tcUser,
            (string) $this->config->legacy->tcPassword,
            'tc-staff-prep',
            $this->config->legacy->tcSslMode ?? 'required',
            $logger,
        );

        [$existingEmails, $existingEntra, $roleCatalog, $existingDetails] = $this->azureLookups($audit);

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
        $analysis = $analyzer->analyze();

        $roles = $roleCatalog->activeRoles();
        $availableRoleCodes = array_values(array_map(static fn (array $r): string => (string) $r['roleCode'], $roles));

        $validEmails = [];
        foreach (is_array($analysis['items'] ?? null) ? $analysis['items'] : [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $normalized = strtolower(trim((string) ($item['normalizedEmail'] ?? '')));
            if ($normalized !== '') {
                $validEmails[] = $normalized;
            }
        }
        $existingUserDetails = [];
        try {
            $existingUserDetails = $existingDetails->byNormalizedEmails(array_values(array_unique($validEmails)));
        } catch (\Throwable) {
        }

        $builder = new StaffMigrationCandidateBuilder();
        $candidates = $builder->build(
            $analysis,
            $availableRoleCodes,
            $existingUserDetails,
            (string) ($analysis['tcEmployeeEnrichment'] ?? 'UNAVAILABLE'),
        );

        $fingerprint = (new StaffMigrationSnapshotFingerprint())->fromAnalysis($analysis);
        $dryRun = (new StaffUserMigrationDryRun())->run($candidates, $analysis, $audit->summary(), $fingerprint);
        $dryRun['triCabRoles'] = $roles;

        if (($audit->summary()['legacyWriteAttemptsBlocked'] ?? 0) > 0) {
            throw new \RuntimeException('Legacy write attempted during preparation.');
        }

        return [
            'analysis' => $analysis,
            'candidates' => $candidates,
            'dryRun' => $dryRun,
            'legacyAudit' => $audit,
        ];
    }

    /**
     * @return array{0: ExistingNormalizedEmailLookup, 1: ExistingEntraLinkLookup, 2: TriCabRoleCatalogLookup, 3: ExistingStaffUserDetailLookup}
     */
    private function azureLookups(ReadOnlySqlAudit $audit): array
    {
        if (!$this->settings->usesAzureSqlUserRepository() || $this->azureConnection === null) {
            return [
                new NullExistingNormalizedEmailLookup(),
                new NullExistingEntraLinkLookup(),
                new NullTriCabRoleCatalogLookup(),
                new NullExistingStaffUserDetailLookup(),
            ];
        }

        $audited = new ReadOnlyAuditedSqlConnection($this->azureConnection, $audit);

        return [
            new AzureSqlExistingNormalizedEmailLookup($audited),
            new AzureSqlExistingEntraLinkLookup($audited),
            new AzureSqlTriCabRoleCatalogLookup($audited),
            new AzureSqlExistingStaffUserDetailLookup($audited),
        ];
    }
}
