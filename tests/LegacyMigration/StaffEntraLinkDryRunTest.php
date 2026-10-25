<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\LegacyMigration;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Identity\Domain\ValueObject\ExternalProvider;
use TriCab\Core\LegacyMigration\Contract\StaffEntraLinkWriteUnit;
use TriCab\Core\LegacyMigration\StaffEntraLink\StaffEntraLinkDryRun;
use TriCab\Core\LegacyMigration\StaffEntraLink\StaffEntraLinkExecutor;
use TriCab\Core\LegacyMigration\StaffEntraLink\StaffEntraLinkProposedAction;
use TriCab\Core\LegacyMigration\StaffEntraLink\StaffEntraLinkSnapshotFingerprint;

final class StaffEntraLinkDryRunTest extends TestCase
{
    public function test_safe_mapped_user_link_with_pending_activation(): void
    {
        $mapping = $this->mapping(100, 1001, 'staff100', 'staff100@tricab.onmicrosoft.com');
        $users = [
            1001 => $this->user(1001, 'staff100@tricab.onmicrosoft.com', 'pending', true),
        ];
        $entra = [
            'oid-100' => $this->entra('oid-100', 'staff100@tricab.com', 'staff100@tricab.com'),
        ];

        $item = $this->runSingle($mapping, $users, $entra)[0];
        self::assertSame(StaffEntraLinkProposedAction::SAFE_LINK_TO_MAPPED_USER, $item['proposedAction']);
        self::assertTrue($item['wouldActivate']);
        self::assertTrue($item['wouldCreateExternalIdentity']);
    }

    public function test_safe_existing_user_link_when_preexisting_is_canonical(): void
    {
        $mapping = $this->mapping(25836, 561, 'tristanguinto', 'tristanguinto@tricab.onmicrosoft.com');
        $users = [
            561 => $this->user(561, 'tristanguinto@tricab.onmicrosoft.com', 'pending', true),
            528 => $this->user(528, 'tristang@tricab.com', 'active', false),
        ];
        $entra = [
            'oid-tristan' => $this->entra('oid-tristan', 'tristang@tricab.com', 'tristang@tricab.com'),
        ];
        $usersByEmail = [
            'tristang@tricab.com' => [$users[528]],
            'tristanguinto@tricab.com' => [$users[561]],
        ];
        $employeeByLogin = ['tristanguinto' => 'EMPLOYEE_VERIFIED'];

        $item = $this->runSingle($mapping, $users, $entra, $usersByEmail, [], [], $employeeByLogin)[0];
        self::assertSame(StaffEntraLinkProposedAction::MULTIPLE_TRICAB_USERS, $item['proposedAction']);
        self::assertSame(528, $item['canonicalUserId']);
        self::assertFalse($item['wouldCreateExternalIdentity']);
        self::assertSame('tristang@tricab.com', $item['corporateEmail']);
    }

    public function test_gmail_address_is_not_auto_entra_linked(): void
    {
        $mapping = ['legacyWordPressUserId' => 20, 'legacyLogin' => '', 'legacyEmailAddress' => 'user@gmail.com', 'normalizedEmailAddress' => 'user@gmail.com', 'userId' => 220];
        $users = [220 => $this->user(220, 'user@gmail.com', 'pending', true)];

        $item = $this->runSingle($mapping, $users, [])[0];
        self::assertSame(StaffEntraLinkProposedAction::NON_TRICAB_EMAIL, $item['proposedAction']);
    }

    public function test_already_linked_identity_is_idempotent(): void
    {
        $mapping = $this->mapping(10, 200, 'linked', 'linked@tricab.com');
        $users = [
            200 => array_merge($this->user(200, 'linked@tricab.com', 'active', true), [
                'externalProviders' => ['microsoft_entra'],
            ]),
        ];
        $entra = [
            'oid-linked' => $this->entra('oid-linked', 'linked@tricab.com', 'linked@tricab.com'),
        ];
        $identitiesByUserId = [
            200 => [[
                'userId' => 200,
                'providerCode' => ExternalProvider::MICROSOFT_ENTRA,
                'subjectIdentifier' => 'oid-linked',
                'tenantIdentifier' => 'tenant-1',
            ]],
        ];
        $identitiesByOid = [
            'oid-linked' => ['userId' => 200, 'subjectIdentifier' => 'oid-linked'],
        ];

        $item = $this->runSingle($mapping, $users, $entra, [], $identitiesByOid, $identitiesByUserId)[0];
        self::assertSame(StaffEntraLinkProposedAction::ALREADY_ENTRA_LINKED, $item['proposedAction']);
        self::assertFalse($item['wouldCreateExternalIdentity']);
        self::assertFalse($item['wouldActivate']);
    }

    public function test_oid_conflict_blocks_link(): void
    {
        $mapping = $this->mapping(11, 201, 'conflict', 'conflict@tricab.com');
        $users = [
            201 => $this->user(201, 'conflict@tricab.com', 'pending', true),
        ];
        $entra = [
            'oid-conflict' => $this->entra('oid-conflict', 'conflict@tricab.com', 'conflict@tricab.com'),
        ];
        $identitiesByOid = [
            'oid-conflict' => ['userId' => 999, 'subjectIdentifier' => 'oid-conflict'],
        ];

        $item = $this->runSingle($mapping, $users, $entra, [], $identitiesByOid)[0];
        self::assertSame(StaffEntraLinkProposedAction::ENTRA_OID_CONFLICT, $item['proposedAction']);
    }

    public function test_no_graph_match(): void
    {
        $mapping = $this->mapping(12, 202, 'nomatch', 'nomatch@tricab.com');
        $users = [
            202 => $this->user(202, 'nomatch@tricab.com', 'pending', true),
        ];

        $item = $this->runSingle($mapping, $users, [])[0];
        self::assertSame(StaffEntraLinkProposedAction::NO_ENTRA_MATCH, $item['proposedAction']);
    }

    public function test_disabled_entra_account(): void
    {
        $mapping = $this->mapping(13, 203, 'disabled', 'disabled@tricab.com');
        $users = [
            203 => $this->user(203, 'disabled@tricab.com', 'pending', true),
        ];
        $entra = [
            'oid-disabled' => array_merge(
                $this->entra('oid-disabled', 'disabled@tricab.com', 'disabled@tricab.com'),
                ['accountEnabled' => false],
            ),
        ];

        $item = $this->runSingle($mapping, $users, $entra)[0];
        self::assertSame(StaffEntraLinkProposedAction::ENTRA_DISABLED, $item['proposedAction']);
    }

    public function test_graph_unavailable_is_not_classified_as_no_entra_match(): void
    {
        $mapping = $this->mapping(12, 202, 'nomatch', 'nomatch@tricab.com');
        $users = [
            202 => $this->user(202, 'nomatch@tricab.com', 'pending', true),
        ];

        $item = $this->runSingle($mapping, $users, [], [], [], [], [], false)[0];
        self::assertSame(StaffEntraLinkProposedAction::GRAPH_DIRECTORY_UNAVAILABLE, $item['proposedAction']);
        self::assertFalse($item['wouldCreateExternalIdentity']);
    }

    public function test_existing_different_entra_oid_is_provider_conflict(): void
    {
        $mapping = $this->mapping(15, 205, 'conflictprov', 'conflictprov@tricab.com');
        $users = [
            205 => array_merge($this->user(205, 'conflictprov@tricab.com', 'pending', true), [
                'externalProviders' => ['microsoft_entra'],
            ]),
        ];
        $entra = [
            'oid-new' => $this->entra('oid-new', 'conflictprov@tricab.com', 'conflictprov@tricab.com'),
        ];
        $identitiesByUserId = [
            205 => [[
                'userId' => 205,
                'providerCode' => 'microsoft_entra',
                'subjectIdentifier' => 'oid-old',
                'tenantIdentifier' => 'tenant-1',
            ]],
        ];

        $item = $this->runSingle($mapping, $users, $entra, [], [], $identitiesByUserId)[0];
        self::assertSame(StaffEntraLinkProposedAction::PROVIDER_CONFLICT, $item['proposedAction']);
        self::assertFalse($item['wouldCreateExternalIdentity']);
    }

    public function test_hyphenated_provider_same_oid_is_already_linked(): void
    {
        $mapping = $this->mapping(16, 206, 'hyphen', 'hyphen@tricab.com');
        $users = [
            206 => array_merge($this->user(206, 'hyphen@tricab.com', 'active', true), [
                'externalProviders' => ['microsoft-entra'],
            ]),
        ];
        $entra = [
            'oid-hyphen' => $this->entra('oid-hyphen', 'hyphen@tricab.com', 'hyphen@tricab.com'),
        ];
        $identitiesByUserId = [
            206 => [[
                'userId' => 206,
                'providerCode' => 'microsoft-entra',
                'subjectIdentifier' => 'oid-hyphen',
                'tenantIdentifier' => 'tenant-1',
            ]],
        ];
        $identitiesByOid = [
            'oid-hyphen' => ['userId' => 206, 'subjectIdentifier' => 'oid-hyphen'],
        ];

        $item = $this->runSingle($mapping, $users, $entra, [], $identitiesByOid, $identitiesByUserId)[0];
        self::assertSame(StaffEntraLinkProposedAction::ALREADY_ENTRA_LINKED, $item['proposedAction']);
        self::assertTrue($item['wouldUpdateProviderToMicrosoftEntra']);
        self::assertFalse($item['wouldCreateExternalIdentity']);
    }

    public function test_active_account_not_redundantly_updated(): void
    {
        $mapping = $this->mapping(14, 204, 'active', 'active@tricab.com');
        $users = [
            204 => $this->user(204, 'active@tricab.com', 'active', true),
        ];
        $entra = [
            'oid-active' => $this->entra('oid-active', 'active@tricab.com', 'active@tricab.com'),
        ];

        $item = $this->runSingle($mapping, $users, $entra)[0];
        self::assertSame(StaffEntraLinkProposedAction::SAFE_LINK_TO_MAPPED_USER, $item['proposedAction']);
        self::assertFalse($item['wouldActivate']);
        self::assertTrue($item['wouldCreateExternalIdentity']);
    }

    public function test_fingerprint_changes_when_entra_state_changes(): void
    {
        $mappings = [$this->mapping(1, 1, 'a', 'a@tricab.com')];
        $users = [1 => $this->user(1, 'a@tricab.com', 'pending', true)];
        $fingerprint = new StaffEntraLinkSnapshotFingerprint();
        $first = $fingerprint->fromState($mappings, $users, [], [], 'tenant-1');
        $second = $fingerprint->fromState(
            $mappings,
            $users,
            ['oid-1' => $this->entra('oid-1', 'a@tricab.com', 'a@tricab.com')],
            [],
            'tenant-1',
        );
        self::assertNotSame($first, $second);
    }

    public function test_executor_skips_non_safe_actions(): void
    {
        $executor = new StaffEntraLinkExecutor(new InMemoryStaffEntraLinkWriteUnit());
        $result = $executor->execute(
            [[
                'proposedAction' => StaffEntraLinkProposedAction::MULTIPLE_TRICAB_USERS,
                'canonicalUserId' => 1,
                'entraObjectId' => 'oid-1',
            ]],
            'batch',
            'fp',
            \TriCab\Core\Identity\Domain\ValueObject\UserId::fromInt(1),
            ExternalProvider::MICROSOFT_ENTRA,
            'tenant-1',
        );
        self::assertSame(0, $result['succeeded']);
        self::assertSame(1, $result['skipped']);
        self::assertSame(0, $result['identityUsersCreated']);
        self::assertSame(0, $result['userRolesModified']);
    }

    public function test_executor_creates_link_and_activates_pending_user(): void
    {
        $writeUnit = new InMemoryStaffEntraLinkWriteUnit();
        $executor = new StaffEntraLinkExecutor($writeUnit);
        $result = $executor->execute(
            [[
                'proposedAction' => StaffEntraLinkProposedAction::SAFE_LINK_TO_MAPPED_USER,
                'canonicalUserId' => 501,
                'entraObjectId' => 'oid-501',
                'entraUpn' => 'staff501@tricab.com',
                'mappedEmail' => 'staff501@tricab.com',
                'wouldActivate' => true,
            ]],
            'batch',
            'fp',
            \TriCab\Core\Identity\Domain\ValueObject\UserId::fromInt(1),
            ExternalProvider::MICROSOFT_ENTRA,
            'tenant-1',
        );
        self::assertSame(1, $result['succeeded']);
        self::assertSame(1, $result['externalIdentitiesCreated']);
        self::assertSame(1, $result['usersActivated']);
    }

    public function test_provider_code_uses_microsoft_entra(): void
    {
        self::assertSame('microsoft_entra', ExternalProvider::MICROSOFT_ENTRA);
    }

    /** @param array<int, array<string, mixed>> $usersById */
    /** @param array<string, array<string, mixed>> $entraByOid */
    /** @param array<string, list<array<string, mixed>>> $usersByEmail */
    /** @param array<string, array<string, mixed>> $identitiesByOid */
    /** @param array<int, list<array<string, mixed>>> $identitiesByUserId */
    /** @param array<string, string> $employeeByLogin */
    private function runSingle(
        array $mapping,
        array $usersById,
        array $entraByOid,
        array $usersByEmail = [],
        array $identitiesByOid = [],
        array $identitiesByUserId = [],
        array $employeeByLogin = [],
        bool $graphDirectoryAvailable = true,
    ): array {
        $entraByEmail = [];
        foreach ($entraByOid as $entra) {
            foreach (['userPrincipalName', 'mail'] as $field) {
                $email = strtolower((string) ($entra[$field] ?? ''));
                if ($email !== '') {
                    $entraByEmail[$email][] = $entra;
                }
            }
        }
        if ($usersByEmail === []) {
            foreach ($usersById as $user) {
                $email = strtolower((string) ($user['normalizedEmailAddress'] ?? ''));
                if ($email !== '') {
                    $usersByEmail[$email][] = $user;
                }
            }
        }

        $dryRun = new StaffEntraLinkDryRun();
        $result = $dryRun->run(
            [$mapping],
            $usersById,
            $entraByOid,
            $entraByEmail,
            $identitiesByOid,
            $identitiesByUserId,
            $usersByEmail,
            'tenant-1',
            ExternalProvider::MICROSOFT_ENTRA,
            ['legacySelectQueries' => 0, 'azureSelectQueries' => 1],
            ['readOperations' => 1, 'writeOperations' => 0],
            'fp-test',
            $employeeByLogin,
            'AVAILABLE',
            $graphDirectoryAvailable,
        );

        return is_array($result['items'] ?? null) ? $result['items'] : [];
    }

    /** @return array<string, mixed> */
    private function mapping(int $wpId, int $userId, string $login, string $email): array
    {
        return [
            'legacyWordPressUserId' => $wpId,
            'legacyLogin' => $login,
            'legacyEmailAddress' => $email,
            'normalizedEmailAddress' => strtolower($email),
            'userId' => $userId,
        ];
    }

    /** @return array<string, mixed> */
    private function user(int $userId, string $email, string $status, bool $batchMapped): array
    {
        return [
            'userId' => $userId,
            'emailAddress' => $email,
            'normalizedEmailAddress' => strtolower($email),
            'statusCode' => $status,
            'roleCodes' => ['EMPLOYEE'],
            'externalProviders' => [],
            'hasEntraLink' => false,
            'isBatch1Mapped' => $batchMapped,
        ];
    }

    /** @return array<string, mixed> */
    private function entra(string $oid, string $upn, string $mail): array
    {
        return [
            'objectId' => $oid,
            'userPrincipalName' => strtolower($upn),
            'mail' => strtolower($mail),
            'displayName' => 'Test User',
            'accountEnabled' => true,
            'tenantId' => 'tenant-1',
        ];
    }
}

final class InMemoryStaffEntraLinkWriteUnit implements StaffEntraLinkWriteUnit
{
    /** @var list<array<string, mixed>> */
    public array $links = [];

    /** @var list<int> */
    public array $activated = [];

    public function linkEntraIdentity(array $link): array
    {
        $this->links[] = $link;
        if ($link['activateIfPending'] === true) {
            $this->activated[] = (int) ($link['userId'] ?? 0);
        }

        return [
            'externalIdentityCreated' => true,
            'userActivated' => $link['activateIfPending'] === true,
        ];
    }
}
