<?php

declare(strict_types=1);

namespace TriCab\Api\LegacyMigration\Application\Service;

use TriCab\Api\Config\Settings;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Api\LegacyMigration\Persistence\AzureSqlStaffEntraIdentityRepository;
use TriCab\Api\LegacyMigration\Persistence\ReadOnlyAuditedSqlConnection;
use TriCab\Api\Product\Migration\ReadOnlyMysqlConnectionFactory;
use TriCab\Core\Identity\Domain\ValueObject\ExternalProvider;
use TriCab\Core\LegacyMigration\Contract\EntraDirectoryReader;
use TriCab\Core\LegacyMigration\Contract\LegacySourceHealthProbe;
use TriCab\Core\LegacyMigration\Contract\StaffEntraIdentityRepository;
use TriCab\Core\LegacyMigration\Live\LegacyIdentitySourceConfig;
use TriCab\Core\LegacyMigration\Support\SourceCatalog;
use TriCab\Core\LegacyMigration\Live\ReadOnlySqlAudit;
use TriCab\Core\LegacyMigration\StaffEntraLink\StaffEntraCorporateEmail;
use TriCab\Core\LegacyMigration\StaffEntraLink\StaffEntraLinkBatchPolicy;
use TriCab\Core\LegacyMigration\StaffEntraLink\StaffEntraLinkDryRun;
use TriCab\Core\LegacyMigration\StaffEntraLink\StaffEntraLinkExecutionException;
use TriCab\Core\LegacyMigration\StaffEntraLink\StaffEntraLinkSnapshotFingerprint;
use TriCab\Core\Product\Migration\Live\InMemoryLegacySqlStatementLogger;

/**
 * Runs STAFF_ENTRA_LINK_ACTIVATION dry-run. Legacy, Azure SQL, and Graph remain read-only.
 */
final class StaffEntraLinkDryRunService
{
    /** @var array<string, mixed>|null */
    private ?array $lastResult = null;

    public function __construct(
        private readonly LegacyIdentitySourceConfig $config,
        private readonly ReadOnlyMysqlConnectionFactory $mysqlFactory,
        private readonly LegacySourceHealthProbe $legacyHealthProbe,
        private readonly Settings $settings,
        private readonly ?SqlConnection $azureConnection,
        private readonly EntraDirectoryReader $entraDirectory,
    ) {
    }

    /** @return array<string, mixed> */
    public function run(): array
    {
        if (!$this->settings->usesAzureSqlUserRepository() || $this->azureConnection === null) {
            throw new \RuntimeException('Staff Entra linking dry-run requires USER_REPOSITORY=azure-sql.');
        }

        $legacySourceHealth = [
            'wordpress' => $this->legacyHealthProbe->probe(SourceCatalog::WORDPRESS) ?? [],
            'business-db' => $this->legacyHealthProbe->probe(SourceCatalog::BUSINESS_DB) ?? [],
        ];
        $this->assertLegacySelectOnly($legacySourceHealth);

        $audit = new ReadOnlySqlAudit();
        $employeeByLogin = $this->loadTcEmployeeLogins($audit);

        $identityRepo = new AzureSqlStaffEntraIdentityRepository(
            new ReadOnlyAuditedSqlConnection($this->azureConnection, $audit),
        );

        $mappings = $identityRepo->listAllStaffMappings();
        if (count($mappings) !== StaffEntraLinkBatchPolicy::EXPECTED_MAPPING_COUNT) {
            throw StaffEntraLinkExecutionException::mappingCountMismatch(
                StaffEntraLinkBatchPolicy::EXPECTED_MAPPING_COUNT,
                count($mappings),
            );
        }

        $mappedUserIds = array_values(array_map(static fn (array $m): int => (int) ($m['userId'] ?? 0), $mappings));
        $usersById = $identityRepo->loadTriCabUsersByIds($mappedUserIds);
        foreach ($usersById as $userId => $user) {
            $usersById[$userId]['isBatch1Mapped'] = true;
        }

        foreach ($identityRepo->loadCorporateStaffUsers() as $userId => $user) {
            if (!isset($usersById[$userId])) {
                $usersById[$userId] = $user;
                $usersById[$userId]['isBatch1Mapped'] = in_array($userId, $mappedUserIds, true);
            }
        }

        $providerCode = $this->resolveProviderCode($identityRepo);
        $expectedTenantId = trim($this->settings->entraTenantId);
        if ($expectedTenantId === '') {
            throw new \RuntimeException('ENTRA_TENANT_ID is required for staff Entra linking dry-run.');
        }

        $graphHealth = $this->entraDirectory->isConfigured()
            ? $this->entraDirectory->verifyHealth($expectedTenantId)
            : [
                'configured' => false,
                'authenticated' => false,
                'directoryRead' => false,
                'httpStatus' => null,
                'graphErrorCode' => 'NOT_CONFIGURED',
                'writeOperations' => 0,
            ];

        $graphAudit = $this->entraDirectory->auditSummary();
        $graphError = null;
        $entraUsers = [];
        if (($graphHealth['directoryRead'] ?? false) === true) {
            try {
                $this->entraDirectory->clearTokenCache();
                $entraUsers = $this->entraDirectory->listStaffDirectoryUsers($expectedTenantId);
                $graphAudit = $this->entraDirectory->auditSummary();
            } catch (\Throwable $exception) {
                $graphError = $exception->getMessage();
                $graphHealth['directoryRead'] = false;
                $graphHealth['graphErrorCode'] = 'DIRECTORY_READ_FAILED';
            }
        } else {
            $graphError = is_string($graphHealth['graphErrorCode'] ?? null)
                ? (string) $graphHealth['graphErrorCode']
                : 'Graph directory read unavailable.';
        }

        [$entraByOid, $entraByNormalizedEmail] = $this->indexEntraUsers($entraUsers);
        $identitiesByOid = $identityRepo->loadEntraIdentitiesBySubject();
        $identitiesByUserId = $identityRepo->loadEntraIdentitiesByUserId();

        $lookupEmails = $this->collectLookupEmails($mappings, $usersById, $entraUsers);
        $usersByNormalizedEmail = $identityRepo->loadTriCabUsersByEmails($lookupEmails);
        foreach ($usersByNormalizedEmail as $email => $users) {
            foreach ($users as $i => $user) {
                $userId = (int) ($user['userId'] ?? 0);
                if (isset($usersById[$userId])) {
                    $users[$i] = $usersById[$userId];
                } else {
                    $users[$i]['isBatch1Mapped'] = in_array($userId, $mappedUserIds, true);
                    $usersById[$userId] = $users[$i];
                }
            }
            $usersByNormalizedEmail[$email] = $users;
        }

        $tcEmployeeEnrichment = $employeeByLogin === [] ? 'UNAVAILABLE' : 'AVAILABLE';
        foreach ($mappings as $mapping) {
            $login = strtolower(trim((string) ($mapping['legacyLogin'] ?? '')));
            if ($login === '') {
                continue;
            }
            if (!isset($employeeByLogin[$login])) {
                $employeeByLogin[$login] = 'EMPLOYEE_NOT_FOUND';
            }
        }

        $fingerprint = (new StaffEntraLinkSnapshotFingerprint())->fromState(
            $mappings,
            $usersById,
            $entraByOid,
            $identitiesByOid,
            $expectedTenantId,
        );

        $dryRun = new StaffEntraLinkDryRun();
        $graphDirectoryAvailable = ($graphHealth['directoryRead'] ?? false) === true;
        $this->lastResult = $dryRun->run(
            $mappings,
            $usersById,
            $entraByOid,
            $entraByNormalizedEmail,
            $identitiesByOid,
            $identitiesByUserId,
            $usersByNormalizedEmail,
            $expectedTenantId,
            $providerCode,
            $this->mergeSafety($audit->summary(), $legacySourceHealth),
            $graphAudit,
            $fingerprint,
            $employeeByLogin,
            $tcEmployeeEnrichment,
            $graphDirectoryAvailable,
        );
        $this->lastResult['providerCodesInDatabase'] = $identityRepo->listProviderCodes();
        $this->lastResult['graphConfigured'] = $this->entraDirectory->isConfigured();
        $this->lastResult['graphHealth'] = $graphDirectoryAvailable
            ? 'AVAILABLE'
            : 'GRAPH_DIRECTORY_UNAVAILABLE';
        $this->lastResult['graphHealthDetail'] = $graphHealth;
        $this->lastResult['graphError'] = $graphError;
        $this->lastResult['legacySourceHealth'] = $legacySourceHealth;
        $this->lastResult['graphClientId'] = trim($this->settings->graphClientId);
        $this->lastResult['graphTenantId'] = trim($this->settings->graphTenantId);
        $this->lastResult['graphSecretConfigured'] = trim($this->settings->graphClientSecret) !== '';

        $this->assertDryRunSafety($audit);

        return $this->lastResult;
    }

    /** @return array<string, mixed>|null */
    public function lastResult(): ?array
    {
        return $this->lastResult;
    }

    /** @param array<string, mixed> $legacySourceHealth */
    private function assertLegacySelectOnly(array $legacySourceHealth): void
    {
        foreach (['wordpress', 'business-db'] as $source) {
            $report = is_array($legacySourceHealth[$source] ?? null) ? $legacySourceHealth[$source] : [];
            if (($report['connected'] ?? false) !== true) {
                throw new \RuntimeException('Legacy source not connected: ' . $source);
            }
            if (($report['readOnlyVerified'] ?? false) !== true && ($report['selectOnlyGrantVerified'] ?? false) !== true) {
                throw new \RuntimeException('Legacy source is not SELECT-only verified: ' . $source);
            }
        }
    }

    private function assertDryRunSafety(ReadOnlySqlAudit $audit): void
    {
        $summary = $audit->summary();
        if (($summary['legacyWriteAttemptsBlocked'] ?? 0) > 0 || ($summary['azureWriteAttemptsBlocked'] ?? 0) > 0) {
            throw new \RuntimeException('DRY RUN FAILED SAFETY REQUIREMENT: write query was attempted.');
        }
    }

    private function resolveProviderCode(StaffEntraIdentityRepository $identityRepo): string
    {
        $codes = $identityRepo->listProviderCodes();
        foreach ($codes as $code) {
            if (str_replace('-', '_', strtolower($code)) === ExternalProvider::MICROSOFT_ENTRA) {
                return ExternalProvider::MICROSOFT_ENTRA;
            }
        }

        return ExternalProvider::MICROSOFT_ENTRA;
    }

    /**
     * @param list<array<string, mixed>> $entraUsers
     * @return array{0: array<string, array<string, mixed>>, 1: array<string, list<array<string, mixed>>>}
     */
    private function indexEntraUsers(array $entraUsers): array
    {
        $byOid = [];
        $byEmail = [];
        foreach ($entraUsers as $entra) {
            $oid = (string) ($entra['objectId'] ?? '');
            if ($oid === '') {
                continue;
            }
            $byOid[$oid] = $entra;
            foreach (['userPrincipalName', 'mail'] as $field) {
                $email = strtolower(trim((string) ($entra[$field] ?? '')));
                if ($email !== '' && StaffEntraCorporateEmail::isCorporate($email)) {
                    $byEmail[$email][] = $entra;
                }
            }
        }

        return [$byOid, $byEmail];
    }

    /**
     * @param list<array<string, mixed>> $mappings
     * @param array<int, array<string, mixed>> $usersById
     * @param list<array<string, mixed>> $entraUsers
     * @return list<string>
     */
    private function collectLookupEmails(array $mappings, array $usersById, array $entraUsers): array
    {
        $emails = [];
        foreach ($mappings as $mapping) {
            $userId = (int) ($mapping['userId'] ?? 0);
            $user = $usersById[$userId] ?? null;
            $normalized = strtolower(trim((string) ($user['normalizedEmailAddress'] ?? $mapping['normalizedEmailAddress'] ?? '')));
            if ($normalized !== '' && StaffEntraCorporateEmail::isCorporate($normalized)) {
                $emails[] = $normalized;
            }
            $login = strtolower(trim((string) ($mapping['legacyLogin'] ?? '')));
            if ($login !== '') {
                $emails[] = $login . StaffEntraCorporateEmail::DOMAIN;
            }
        }
        foreach ($usersById as $user) {
            $email = strtolower(trim((string) ($user['normalizedEmailAddress'] ?? '')));
            if (StaffEntraCorporateEmail::isCorporate($email)) {
                $emails[] = $email;
            }
        }
        foreach ($entraUsers as $entra) {
            foreach (['userPrincipalName', 'mail'] as $field) {
                $email = strtolower(trim((string) ($entra[$field] ?? '')));
                if ($email !== '' && StaffEntraCorporateEmail::isCorporate($email)) {
                    $emails[] = $email;
                }
            }
        }

        return array_values(array_unique($emails));
    }

    /** @return array<string, string> */
    private function loadTcEmployeeLogins(ReadOnlySqlAudit $audit): array
    {
        if (!$this->config->liveExtractionConfigured()) {
            return [];
        }

        $logger = new InMemoryLegacySqlStatementLogger();
        try {
            $tc = $this->mysqlFactory->create(
                (string) $this->config->legacy->tcDsn,
                (string) $this->config->legacy->tcUser,
                (string) $this->config->legacy->tcPassword,
                'tc-entra-employee-read',
                $this->config->legacy->tcSslMode ?? 'required',
                $logger,
            );
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        $lastLogin = '';
        while (true) {
            $sql = 'SELECT user_login FROM `employee` WHERE user_login > :lastLogin ORDER BY user_login ASC LIMIT 500';
            try {
                $audit->recordLegacy($sql);
                $rows = $tc->query($sql, [':lastLogin' => $lastLogin], 'staff-entra-link', 'tcEmployee');
            } catch (\Throwable) {
                break;
            }
            if ($rows === []) {
                break;
            }
            foreach ($rows as $row) {
                $login = strtolower(trim((string) ($row['user_login'] ?? '')));
                if ($login === '') {
                    continue;
                }
                $lastLogin = $login;
                $out[$login] = 'EMPLOYEE_VERIFIED';
            }
            if (count($rows) < 500) {
                break;
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $azure */
    /** @param array<string, mixed> $legacy */
    private function mergeSafety(array $azure, array $legacy): array
    {
        $wp = is_array($legacy['wordpress'] ?? null) ? $legacy['wordpress'] : [];
        $tc = is_array($legacy['business-db'] ?? null) ? $legacy['business-db'] : [];

        return array_merge($azure, [
            'legacyWordPressSelect' => (int) ($azure['legacySelectQueries'] ?? 0),
            'legacyTcSelect' => (int) ($azure['legacySelectQueries'] ?? 0),
            'legacyWordPressConnected' => ($wp['connected'] ?? false) === true,
            'legacyTcConnected' => ($tc['connected'] ?? false) === true,
            'legacyWordPressSelectOnly' => ($wp['readOnlyVerified'] ?? false) === true || ($wp['selectOnlyGrantVerified'] ?? false) === true,
            'legacyTcSelectOnly' => ($tc['readOnlyVerified'] ?? false) === true || ($tc['selectOnlyGrantVerified'] ?? false) === true,
            'developmentOverride' => ($wp['developmentOverrideEnabled'] ?? false) === true || ($tc['developmentOverrideEnabled'] ?? false) === true,
        ]);
    }
}
