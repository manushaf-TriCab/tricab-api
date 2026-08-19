<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\StaffEntraLink;

/**
 * Computes Entra linking dry-run outcomes. Never writes to any database or Graph.
 */
final class StaffEntraLinkDryRun
{
    /**
     * @param list<array<string, mixed>> $mappings
     * @param array<int, array<string, mixed>> $usersById
     * @param array<string, array<string, mixed>> $entraByOid
     * @param array<string, list<array<string, mixed>>> $entraByNormalizedEmail
     * @param array<string, array<string, mixed>> $identitiesByOid
     * @param array<int, list<array<string, mixed>>> $identitiesByUserId
     * @param array<string, list<array<string, mixed>>> $usersByNormalizedEmail
     * @param array<string, mixed> $databaseSafety
     * @param array<string, mixed> $graphAudit
     * @param array<string, string> $employeeByLogin legacy login => EMPLOYEE_VERIFIED|EMPLOYEE_NOT_FOUND|...
     */
    public function run(
        array $mappings,
        array $usersById,
        array $entraByOid,
        array $entraByNormalizedEmail,
        array $identitiesByOid,
        array $identitiesByUserId,
        array $usersByNormalizedEmail,
        string $expectedTenantId,
        string $providerCode,
        array $databaseSafety,
        array $graphAudit,
        string $sourceFingerprint,
        array $employeeByLogin = [],
        string $tcEmployeeEnrichment = 'UNAVAILABLE',
        bool $graphDirectoryAvailable = true,
    ): array {
        $items = [];
        foreach ($mappings as $mapping) {
            $items[] = $this->resolveMapping(
                $mapping,
                $usersById,
                $entraByOid,
                $entraByNormalizedEmail,
                $identitiesByOid,
                $identitiesByUserId,
                $usersByNormalizedEmail,
                $expectedTenantId,
                $providerCode,
                $employeeByLogin,
                $graphDirectoryAvailable,
            );
        }

        $summary = $this->buildSummary($items, $graphDirectoryAvailable);
        $summary['tcEmployeeEnrichment'] = $tcEmployeeEnrichment;
        $summary['graphDirectoryAvailable'] = $graphDirectoryAvailable;
        $summary['graphLookupAttempted'] = $graphDirectoryAvailable;
        $tristan = $this->findTristanReconciliation(
            $mappings,
            $usersById,
            $entraByOid,
            $entraByNormalizedEmail,
            $identitiesByOid,
            $items,
        );

        return [
            'mode' => 'DRY_RUN_ONLY',
            'batchType' => StaffEntraLinkBatchPolicy::BATCH_TYPE,
            'warning' => 'Legacy databases: READ ONLY. Graph: READ ONLY. No ExternalIdentities will be created during preview.',
            'persistence' => 'azure-sql',
            'sourceFingerprint' => $sourceFingerprint,
            'approvalRequired' => true,
            'executeEnabled' => false,
            'expectedTenantId' => $expectedTenantId,
            'providerCode' => $providerCode,
            'canonicalProviderCode' => $providerCode,
            'graphDirectoryAvailable' => $graphDirectoryAvailable,
            'mappedStaffCount' => count($mappings),
            'summary' => $summary,
            'wouldCreate' => [
                'externalIdentities' => $summary['wouldCreateExternalIdentities'],
                'userActivations' => $summary['wouldActivateUsers'],
                'providerUpdatesToMicrosoftEntra' => $summary['wouldUpdateProviderToMicrosoftEntra'],
                'identityUsers' => 0,
                'userRoles' => 0,
                'legacyMappings' => 0,
            ],
            'items' => $items,
            'tristanReconciliation' => $tristan,
            'databaseSafety' => $databaseSafety,
            'graphSafety' => [
                'readOperations' => (int) ($graphAudit['readOperations'] ?? 0),
                'writeOperations' => 0,
            ],
            'staleDataProtection' => [
                'fingerprint' => $sourceFingerprint,
                'rule' => 'Execution blocked when approved fingerprint != current source fingerprint',
                'staleCode' => 'STALE_ENTRA_LINK_PREVIEW',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $mapping
     * @param array<int, array<string, mixed>> $usersById
     * @param array<string, array<string, mixed>> $entraByOid
     * @param array<string, list<array<string, mixed>>> $entraByNormalizedEmail
     * @param array<string, array<string, mixed>> $identitiesByOid
     * @param array<int, list<array<string, mixed>>> $identitiesByUserId
     * @param array<string, list<array<string, mixed>>> $usersByNormalizedEmail
     * @return array<string, mixed>
     */
    private function resolveMapping(
        array $mapping,
        array $usersById,
        array $entraByOid,
        array $entraByNormalizedEmail,
        array $identitiesByOid,
        array $identitiesByUserId,
        array $usersByNormalizedEmail,
        string $expectedTenantId,
        string $providerCode,
        array $employeeByLogin,
        bool $graphDirectoryAvailable,
    ): array {
        $mappedUserId = (int) ($mapping['userId'] ?? 0);
        $mappedUser = $usersById[$mappedUserId] ?? null;
        $legacyLogin = strtolower(trim((string) ($mapping['legacyLogin'] ?? '')));
        $mappedEmail = strtolower(trim((string) ($mappedUser['normalizedEmailAddress'] ?? $mapping['normalizedEmailAddress'] ?? '')));
        $tcEmployeeStatus = (string) ($employeeByLogin[$legacyLogin] ?? 'EMPLOYEE_NOT_FOUND');

        $corporateEmail = $this->resolveCorporateEmail(
            $mappedEmail,
            $legacyLogin,
            $usersById,
            $usersByNormalizedEmail,
            $tcEmployeeStatus,
        );

        if ($corporateEmail === null) {
            return $this->itemRow(
                $mapping,
                $mappedUser,
                null,
                StaffEntraLinkProposedAction::NON_TRICAB_EMAIL,
                null,
                null,
                false,
                false,
                'none',
                ['No @tricab.com corporate email available for verified Entra lookup.'],
                null,
                $tcEmployeeStatus,
                StaffEntraCorporateEmail::isCorporate($mappedEmail),
            );
        }

        if (!$graphDirectoryAvailable) {
            return $this->itemRow(
                $mapping,
                $mappedUser,
                null,
                StaffEntraLinkProposedAction::GRAPH_DIRECTORY_UNAVAILABLE,
                null,
                null,
                false,
                false,
                'none',
                ['Graph directory is unavailable. Entra matching was not attempted.'],
                null,
                $tcEmployeeStatus,
                true,
                $corporateEmail,
            );
        }

        $entraMatches = $this->findEntraMatches(
            $mappedUserId,
            $corporateEmail,
            $identitiesByUserId,
            $entraByOid,
            $entraByNormalizedEmail,
        );

        if ($entraMatches === []) {
            return $this->itemRow(
                $mapping,
                $mappedUser,
                null,
                StaffEntraLinkProposedAction::NO_ENTRA_MATCH,
                null,
                null,
                false,
                false,
                'none',
                ['No verified Entra @tricab.com account found for ' . $corporateEmail . '.'],
                null,
                $tcEmployeeStatus,
                true,
                $corporateEmail,
            );
        }

        if (count($entraMatches) > 1) {
            return $this->itemRow(
                $mapping,
                $mappedUser,
                $entraMatches[0],
                StaffEntraLinkProposedAction::MULTIPLE_ENTRA_MATCHES,
                null,
                null,
                false,
                false,
                'low',
                ['Multiple Entra directory users match ' . $corporateEmail . '.'],
                null,
                $tcEmployeeStatus,
                true,
                $corporateEmail,
            );
        }

        $entraMatch = $entraMatches[0];

        $issues = [];
        $confidence = 'none';
        $proposedAction = StaffEntraLinkProposedAction::NO_ENTRA_MATCH;
        $canonicalUserId = null;
        $canonicalRecommendation = null;
        $wouldActivate = false;
        $wouldCreateLink = false;
        $entraOid = $entraMatch['objectId'] ?? null;
        $entraUpn = $entraMatch['userPrincipalName'] ?? null;
        $entraMail = $entraMatch['mail'] ?? null;
        $entraEnabled = $entraMatch['accountEnabled'] ?? null;

        if (!$this->entraMatchesCorporateEmail($entraMatch, $corporateEmail)) {
            return $this->itemRow(
                $mapping,
                $mappedUser,
                $entraMatch,
                StaffEntraLinkProposedAction::EMAIL_UPN_MISMATCH,
                null,
                null,
                false,
                false,
                'medium',
                ['Entra UPN/mail does not exactly match corporate email ' . $corporateEmail . '.'],
                null,
                $tcEmployeeStatus,
                true,
                $corporateEmail,
            );
        }

        if ($entraOid === null || $entraOid === '') {
            return $this->itemRow(
                $mapping,
                $mappedUser,
                null,
                StaffEntraLinkProposedAction::NO_ENTRA_MATCH,
                null,
                null,
                false,
                false,
                'none',
                ['No verified Entra object found for this staff member.'],
            );
        }

        if (($entraMatch['tenantId'] ?? '') !== '' && ($entraMatch['tenantId'] ?? '') !== $expectedTenantId) {
            $issues[] = 'Entra tenant mismatch.';
            $proposedAction = StaffEntraLinkProposedAction::MANUAL_REVIEW_REQUIRED;
        }

        if ($entraEnabled === false) {
            return $this->itemRow(
                $mapping,
                $mappedUser,
                $entraMatch,
                StaffEntraLinkProposedAction::ENTRA_DISABLED,
                null,
                null,
                false,
                false,
                'high',
                ['Entra account is disabled.'],
                null,
                $tcEmployeeStatus,
                true,
                $corporateEmail,
            );
        }

        $existingOidLink = $identitiesByOid[$entraOid] ?? null;
        $candidateUserIds = $this->collectTriCabCandidates(
            $mappedUserId,
            $corporateEmail,
            $legacyLogin,
            $entraMatch,
            $usersByNormalizedEmail,
            $existingOidLink,
        );

        $candidateUsers = [];
        foreach (array_values(array_unique($candidateUserIds)) as $userId) {
            if (isset($usersById[$userId])) {
                $candidateUsers[] = $usersById[$userId];
            }
        }

        if ($existingOidLink !== null) {
            $linkedUserId = (int) ($existingOidLink['userId'] ?? 0);
            $mappedHasExactLink = $this->userHasEntraLink($mappedUserId, $entraOid, $identitiesByUserId);
            if ($mappedHasExactLink) {
                return $this->itemRow(
                    $mapping,
                    $mappedUser,
                    $entraMatch,
                    StaffEntraLinkProposedAction::ALREADY_ENTRA_LINKED,
                    $mappedUserId,
                    null,
                    false,
                    false,
                    'verified',
                    [],
                    null,
                    $tcEmployeeStatus,
                    true,
                    $corporateEmail,
                    $this->wouldNormalizeProviderSpelling($mappedUserId, $entraOid, $identitiesByUserId),
                );
            }
            if ($linkedUserId !== $mappedUserId && count($candidateUsers) > 1) {
                $canonical = $this->recommendCanonicalUser($candidateUsers, $mappedUserId);
                return $this->itemRow(
                    $mapping,
                    $mappedUser,
                    $entraMatch,
                    StaffEntraLinkProposedAction::ENTRA_OID_CONFLICT,
                    $canonical['userId'],
                    $canonical['recommendation'],
                    false,
                    false,
                    'verified',
                    [
                        sprintf('Entra OID already linked to UserId %d.', $linkedUserId),
                        'Multiple TriCab users match this Entra identity.',
                    ],
                    $candidateUsers,
                    $tcEmployeeStatus,
                    true,
                    $corporateEmail,
                );
            }
            if ($linkedUserId !== $mappedUserId) {
                return $this->itemRow(
                    $mapping,
                    $mappedUser,
                    $entraMatch,
                    StaffEntraLinkProposedAction::ENTRA_OID_CONFLICT,
                    $linkedUserId,
                    StaffEntraLinkCanonicalRecommendation::KEEP_EXISTING,
                    false,
                    false,
                    'verified',
                    [sprintf('Entra OID already linked to UserId %d.', $linkedUserId)],
                    null,
                    $tcEmployeeStatus,
                    true,
                    $corporateEmail,
                );
            }
        }

        if (count($candidateUsers) > 1) {
            $canonical = $this->recommendCanonicalUser($candidateUsers, $mappedUserId);
            $issues[] = 'Multiple TriCab users match this Entra identity.';
            if ($canonical['userId'] !== $mappedUserId) {
                $issues[] = sprintf('Recommended canonical UserId %d (%s).', $canonical['userId'], $canonical['reason']);
            }

            return $this->itemRow(
                $mapping,
                $mappedUser,
                $entraMatch,
                StaffEntraLinkProposedAction::MULTIPLE_TRICAB_USERS,
                $canonical['userId'],
                $canonical['recommendation'],
                false,
                false,
                'medium',
                $issues,
                $candidateUsers,
                $tcEmployeeStatus,
                true,
                $corporateEmail,
            );
        }

        $canonicalUserId = count($candidateUsers) === 1 ? (int) ($candidateUsers[0]['userId'] ?? 0) : $mappedUserId;
        $canonicalUser = $usersById[$canonicalUserId] ?? $mappedUser;

        if ($this->userHasEntraLink($canonicalUserId, $entraOid, $identitiesByUserId)) {
            return $this->itemRow(
                $mapping,
                $mappedUser,
                $entraMatch,
                StaffEntraLinkProposedAction::ALREADY_ENTRA_LINKED,
                $canonicalUserId,
                null,
                false,
                false,
                'verified',
                [],
                null,
                $tcEmployeeStatus,
                true,
                $corporateEmail,
                $this->wouldNormalizeProviderSpelling($canonicalUserId, $entraOid, $identitiesByUserId),
            );
        }

        $providerConflict = $this->existingEntraProviderConflict($canonicalUserId, $entraOid, $identitiesByUserId);
        if ($providerConflict !== null) {
            return $this->itemRow(
                $mapping,
                $mappedUser,
                $entraMatch,
                StaffEntraLinkProposedAction::PROVIDER_CONFLICT,
                $canonicalUserId,
                $canonicalRecommendation,
                false,
                false,
                'verified',
                [$providerConflict],
                $candidateUsers !== [] ? $candidateUsers : null,
                $tcEmployeeStatus,
                true,
                $corporateEmail,
            );
        }

        if ($canonicalUserId === $mappedUserId) {
            $proposedAction = StaffEntraLinkProposedAction::SAFE_LINK_TO_MAPPED_USER;
            $confidence = 'high';
            $wouldCreateLink = true;
            if (($canonicalUser['statusCode'] ?? '') === 'pending') {
                $wouldActivate = true;
            }
        } else {
            $proposedAction = StaffEntraLinkProposedAction::SAFE_LINK_TO_EXISTING_USER;
            $confidence = 'high';
            $wouldCreateLink = true;
            if (($canonicalUser['statusCode'] ?? '') === 'pending') {
                $wouldActivate = true;
            }
            $canonicalRecommendation = StaffEntraLinkCanonicalRecommendation::KEEP_EXISTING;
        }

        if ($tcEmployeeStatus === 'EMPLOYEE_CONFLICT' || $tcEmployeeStatus === 'EMPLOYEE_LOGIN_MISMATCH') {
            return $this->itemRow(
                $mapping,
                $mappedUser,
                $entraMatch,
                StaffEntraLinkProposedAction::MANUAL_REVIEW_REQUIRED,
                $canonicalUserId,
                $canonicalRecommendation,
                false,
                false,
                'low',
                array_merge($issues, ['TC employee verification conflict.']),
                $candidateUsers !== [] ? $candidateUsers : null,
                $tcEmployeeStatus,
                true,
                $corporateEmail,
            );
        }

        return $this->itemRow(
            $mapping,
            $mappedUser,
            $entraMatch,
            $proposedAction,
            $canonicalUserId,
            $canonicalRecommendation,
            $wouldActivate,
            $wouldCreateLink,
            $confidence,
            $issues,
            $candidateUsers !== [] ? $candidateUsers : null,
            $tcEmployeeStatus,
            true,
            $corporateEmail,
        );
    }

    /**
     * @param array<int, array<string, mixed>> $usersById
     * @param array<string, list<array<string, mixed>>> $usersByNormalizedEmail
     */
    private function resolveCorporateEmail(
        string $mappedEmail,
        string $legacyLogin,
        array $usersById,
        array $usersByNormalizedEmail,
        string $tcEmployeeStatus,
    ): ?string {
        if (StaffEntraCorporateEmail::isCorporate($mappedEmail)) {
            return $mappedEmail;
        }

        if ($legacyLogin !== '') {
            if ($tcEmployeeStatus === 'EMPLOYEE_VERIFIED') {
                foreach ($usersById as $user) {
                    $email = strtolower(trim((string) ($user['normalizedEmailAddress'] ?? '')));
                    if (!StaffEntraCorporateEmail::isCorporate($email)) {
                        continue;
                    }
                    $local = StaffEntraCorporateEmail::localPart($email);
                    if (strlen($local) >= 5 && str_starts_with($legacyLogin, $local)) {
                        return $email;
                    }
                }
            }

            return $legacyLogin . StaffEntraCorporateEmail::DOMAIN;
        }

        return null;
    }

    /**
     * @param array<int, list<array<string, mixed>>> $identitiesByUserId
     * @param array<string, array<string, mixed>> $entraByOid
     * @param array<string, list<array<string, mixed>>> $entraByNormalizedEmail
     * @return list<array<string, mixed>>
     */
    private function findEntraMatches(
        int $mappedUserId,
        string $corporateEmail,
        array $identitiesByUserId,
        array $entraByOid,
        array $entraByNormalizedEmail,
    ): array {
        foreach ($identitiesByUserId[$mappedUserId] ?? [] as $identity) {
            $oid = (string) ($identity['subjectIdentifier'] ?? '');
            if ($oid !== '' && isset($entraByOid[$oid])) {
                return [$entraByOid[$oid]];
            }
        }

        $matches = [];
        $seen = [];
        foreach ($entraByNormalizedEmail[$corporateEmail] ?? [] as $entra) {
            $oid = (string) ($entra['objectId'] ?? '');
            if ($oid === '' || isset($seen[$oid])) {
                continue;
            }
            if ($this->entraMatchesCorporateEmail($entra, $corporateEmail)) {
                $seen[$oid] = true;
                $matches[] = $entra;
            }
        }

        return $matches;
    }

    /** @param array<string, mixed> $entraMatch */
    private function entraMatchesCorporateEmail(array $entraMatch, string $corporateEmail): bool
    {
        $corporateEmail = strtolower(trim($corporateEmail));
        $upn = strtolower(trim((string) ($entraMatch['userPrincipalName'] ?? '')));
        $mail = strtolower(trim((string) ($entraMatch['mail'] ?? '')));

        return $upn === $corporateEmail || $mail === $corporateEmail;
    }

    /**
     * @param array<string, mixed>|null $existingOidLink
     * @return list<int>
     */
    private function collectTriCabCandidates(
        int $mappedUserId,
        string $corporateEmail,
        string $legacyLogin,
        array $entraMatch,
        array $usersByNormalizedEmail,
        ?array $existingOidLink,
    ): array {
        $ids = [$mappedUserId];
        if ($existingOidLink !== null) {
            $ids[] = (int) ($existingOidLink['userId'] ?? 0);
        }

        $emails = array_filter([
            $corporateEmail,
            strtolower((string) ($entraMatch['userPrincipalName'] ?? '')),
            strtolower((string) ($entraMatch['mail'] ?? '')),
        ]);

        foreach (array_unique($emails) as $email) {
            if (!StaffEntraCorporateEmail::isCorporate($email)) {
                continue;
            }
            foreach ($usersByNormalizedEmail[$email] ?? [] as $user) {
                $ids[] = (int) ($user['userId'] ?? 0);
            }
        }

        return array_values(array_filter(array_unique($ids), static fn (int $id): bool => $id > 0));
    }

    /**
     * @param list<array<string, mixed>> $candidateUsers
     * @return array{userId: int, recommendation: string, reason: string}
     */
    private function recommendCanonicalUser(array $candidateUsers, int $mappedUserId): array
    {
        usort($candidateUsers, function (array $a, array $b) use ($mappedUserId): int {
            $scoreA = $this->canonicalScore($a, $mappedUserId);
            $scoreB = $this->canonicalScore($b, $mappedUserId);

            return $scoreB <=> $scoreA;
        });

        $best = $candidateUsers[0];
        $bestId = (int) ($best['userId'] ?? 0);
        $recommendation = $bestId === $mappedUserId
            ? StaffEntraLinkCanonicalRecommendation::KEEP_MIGRATED
            : StaffEntraLinkCanonicalRecommendation::KEEP_EXISTING;

        $reasons = [];
        if (($best['statusCode'] ?? '') === 'active') {
            $reasons[] = 'active status';
        }
        if (str_ends_with((string) ($best['normalizedEmailAddress'] ?? ''), '@tricab.com')) {
            $reasons[] = 'corporate email';
        }
        if (($best['hasEntraLink'] ?? false) === true) {
            $reasons[] = 'existing Entra link';
        }
        if (($best['isBatch1Mapped'] ?? false) !== true) {
            $reasons[] = 'pre-existing account';
        }

        return [
            'userId' => $bestId,
            'recommendation' => count($candidateUsers) > 2
                ? StaffEntraLinkCanonicalRecommendation::MANUAL_REVIEW
                : $recommendation,
            'reason' => $reasons !== [] ? implode(', ', $reasons) : 'best canonical match score',
        ];
    }

    /** @param array<string, mixed> $user */
    private function canonicalScore(array $user, int $mappedUserId): int
    {
        $score = 0;
        if (($user['statusCode'] ?? '') === 'active') {
            $score += 100;
        }
        if (($user['hasEntraLink'] ?? false) === true) {
            $score += 80;
        }
        if (str_ends_with((string) ($user['normalizedEmailAddress'] ?? ''), '@tricab.com')) {
            $score += 50;
        }
        if (($user['isBatch1Mapped'] ?? false) !== true) {
            $score += 30;
        }
        if ((int) ($user['userId'] ?? 0) === $mappedUserId) {
            $score += 5;
        }

        return $score;
    }

    /** @param array<int, list<array<string, mixed>>> $identitiesByUserId */
    private function userHasEntraLink(int $userId, string $oid, array $identitiesByUserId): bool
    {
        foreach ($identitiesByUserId[$userId] ?? [] as $identity) {
            if ((string) ($identity['subjectIdentifier'] ?? '') === $oid) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, list<array<string, mixed>>> $identitiesByUserId
     */
    private function wouldNormalizeProviderSpelling(int $userId, string $oid, array $identitiesByUserId): bool
    {
        foreach ($identitiesByUserId[$userId] ?? [] as $identity) {
            if ((string) ($identity['subjectIdentifier'] ?? '') !== $oid) {
                continue;
            }

            return strtolower(trim((string) ($identity['providerCode'] ?? ''))) !== 'microsoft_entra';
        }

        return false;
    }

    /**
     * @param array<int, list<array<string, mixed>>> $identitiesByUserId
     */
    private function existingEntraProviderConflict(int $userId, string $oid, array $identitiesByUserId): ?string
    {
        foreach ($identitiesByUserId[$userId] ?? [] as $identity) {
            $subject = (string) ($identity['subjectIdentifier'] ?? '');
            $provider = strtolower(str_replace('-', '_', (string) ($identity['providerCode'] ?? '')));
            if ($provider !== 'microsoft_entra') {
                continue;
            }
            if ($subject !== '' && $subject !== $oid) {
                return sprintf(
                    'UserId %d is already linked to a different Entra Object ID. Existing subject will not be overwritten.',
                    $userId,
                );
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $mapping
     * @param array<string, mixed>|null $mappedUser
     * @param array<string, mixed>|null $entraMatch
     * @param list<string> $issues
     * @param list<array<string, mixed>>|null $matchingUsers
     * @return array<string, mixed>
     */
    private function itemRow(
        array $mapping,
        ?array $mappedUser,
        ?array $entraMatch,
        string $proposedAction,
        ?int $canonicalUserId,
        ?string $canonicalRecommendation,
        bool $wouldActivate,
        bool $wouldCreateLink,
        string $confidence,
        array $issues,
        ?array $matchingUsers = null,
        string $tcEmployeeStatus = 'EMPLOYEE_NOT_FOUND',
        bool $isCorporateEligible = false,
        ?string $corporateEmail = null,
        bool $wouldUpdateProviderToMicrosoftEntra = false,
    ): array {
        $currentLink = null;
        if ($mappedUser !== null && ($mappedUser['externalProviders'] ?? []) !== []) {
            $currentLink = implode(',', $mappedUser['externalProviders']);
        }

        return [
            'legacyWordPressUserId' => (int) ($mapping['legacyWordPressUserId'] ?? 0),
            'legacyLogin' => (string) ($mapping['legacyLogin'] ?? ''),
            'mappedUserId' => (int) ($mapping['userId'] ?? 0),
            'mappedEmail' => (string) ($mappedUser['emailAddress'] ?? $mapping['legacyEmailAddress'] ?? ''),
            'corporateEmail' => $corporateEmail,
            'isCorporateEligible' => $isCorporateEligible,
            'tcEmployeeStatus' => $tcEmployeeStatus,
            'currentStatus' => (string) ($mappedUser['statusCode'] ?? 'unknown'),
            'currentRoles' => $mappedUser['roleCodes'] ?? [],
            'entraObjectId' => $entraMatch['objectId'] ?? null,
            'entraUpn' => $entraMatch['userPrincipalName'] ?? null,
            'entraMail' => $entraMatch['mail'] ?? null,
            'entraAccountEnabled' => $entraMatch['accountEnabled'] ?? null,
            'entraMatch' => $entraMatch !== null,
            'currentLink' => $currentLink,
            'canonicalUserId' => $canonicalUserId,
            'canonicalRecommendation' => $canonicalRecommendation,
            'proposedAction' => $proposedAction,
            'confidence' => $confidence,
            'wouldActivate' => $wouldActivate,
            'wouldCreateExternalIdentity' => $wouldCreateLink,
            'wouldUpdateProviderToMicrosoftEntra' => $wouldUpdateProviderToMicrosoftEntra,
            'canonicalProviderCode' => 'microsoft_entra',
            'issues' => $issues,
            'matchingTriCabUsers' => $matchingUsers !== null
                ? array_map(static fn (array $u): array => [
                    'userId' => (int) ($u['userId'] ?? 0),
                    'email' => (string) ($u['emailAddress'] ?? ''),
                    'status' => (string) ($u['statusCode'] ?? ''),
                    'roles' => $u['roleCodes'] ?? [],
                    'hasEntraLink' => (bool) ($u['hasEntraLink'] ?? false),
                    'isBatch1Mapped' => (bool) ($u['isBatch1Mapped'] ?? false),
                ], $matchingUsers)
                : [],
        ];
    }

    /** @param list<array<string, mixed>> $items */
    /** @param list<array<string, mixed>> $mappings */
    /** @param array<int, array<string, mixed>> $usersById */
    private function buildSummary(array $items, bool $graphDirectoryAvailable): array
    {
        $counts = [
            'mappedStaffUsers' => count($items),
            'tricabCorporateStaff' => 0,
            'nonTricabCorporateStaff' => 0,
            'tcEmployeeVerified' => 0,
            'tcEmployeeNotFound' => 0,
            'tcEmployeeConflicts' => 0,
            'alreadyLinked' => 0,
            'alreadyEntraLinked' => 0,
            'safeLinksToMappedUsers' => 0,
            'safeLinksToExistingUsers' => 0,
            'uniqueEntraMatches' => 0,
            'noEntraMatch' => 0,
            'graphDirectoryUnavailable' => 0,
            'multipleEntraMatches' => 0,
            'multipleTriCabUserMatches' => 0,
            'oidConflicts' => 0,
            'disabledEntraAccounts' => 0,
            'entraDisabled' => 0,
            'emailUpnConflicts' => 0,
            'providerConflicts' => 0,
            'nonTricabEmail' => 0,
            'manualReviewRequired' => 0,
            'wouldCreateExternalIdentities' => 0,
            'wouldActivateUsers' => 0,
            'wouldUpdateProviderToMicrosoftEntra' => 0,
            'wouldModifyExistingUserRecords' => 0,
            'conflictsExcluded' => 0,
            'safeToLink' => 0,
            'safeToActivate' => 0,
            'graphLookupAttempted' => $graphDirectoryAvailable ? count($items) : 0,
        ];

        foreach ($items as $item) {
            if (($item['isCorporateEligible'] ?? false) === true) {
                ++$counts['tricabCorporateStaff'];
            } else {
                ++$counts['nonTricabCorporateStaff'];
            }
            match ((string) ($item['tcEmployeeStatus'] ?? '')) {
                'EMPLOYEE_VERIFIED' => ++$counts['tcEmployeeVerified'],
                'EMPLOYEE_CONFLICT', 'EMPLOYEE_LOGIN_MISMATCH' => ++$counts['tcEmployeeConflicts'],
                default => ++$counts['tcEmployeeNotFound'],
            };

            $action = (string) ($item['proposedAction'] ?? '');
            match ($action) {
                StaffEntraLinkProposedAction::ALREADY_ENTRA_LINKED => ++$counts['alreadyEntraLinked'],
                StaffEntraLinkProposedAction::SAFE_LINK_TO_MAPPED_USER => ++$counts['safeLinksToMappedUsers'],
                StaffEntraLinkProposedAction::SAFE_LINK_TO_EXISTING_USER => ++$counts['safeLinksToExistingUsers'],
                StaffEntraLinkProposedAction::NO_ENTRA_MATCH => ++$counts['noEntraMatch'],
                StaffEntraLinkProposedAction::GRAPH_DIRECTORY_UNAVAILABLE => ++$counts['graphDirectoryUnavailable'],
                StaffEntraLinkProposedAction::MULTIPLE_TRICAB_USERS => ++$counts['multipleTriCabUserMatches'],
                StaffEntraLinkProposedAction::ENTRA_OID_CONFLICT => ++$counts['oidConflicts'],
                StaffEntraLinkProposedAction::ENTRA_DISABLED => ++$counts['entraDisabled'],
                StaffEntraLinkProposedAction::EMAIL_CONFLICT, StaffEntraLinkProposedAction::EMAIL_UPN_MISMATCH => ++$counts['emailUpnConflicts'],
                StaffEntraLinkProposedAction::NON_TRICAB_EMAIL => ++$counts['nonTricabEmail'],
                StaffEntraLinkProposedAction::MULTIPLE_ENTRA_MATCHES => ++$counts['multipleEntraMatches'],
                StaffEntraLinkProposedAction::PROVIDER_CONFLICT => ++$counts['providerConflicts'],
                StaffEntraLinkProposedAction::MANUAL_REVIEW_REQUIRED => ++$counts['manualReviewRequired'],
                default => null,
            };

            $counts['alreadyLinked'] = $counts['alreadyEntraLinked'];
            $counts['disabledEntraAccounts'] = $counts['entraDisabled'];

            if (($item['entraMatch'] ?? false) === true && $action !== StaffEntraLinkProposedAction::MULTIPLE_ENTRA_MATCHES) {
                ++$counts['uniqueEntraMatches'];
            }

            if (in_array($action, [
                StaffEntraLinkProposedAction::SAFE_LINK_TO_MAPPED_USER,
                StaffEntraLinkProposedAction::SAFE_LINK_TO_EXISTING_USER,
            ], true)) {
                ++$counts['safeToLink'];
            }

            if (($item['wouldCreateExternalIdentity'] ?? false) === true) {
                ++$counts['wouldCreateExternalIdentities'];
            }
            if (($item['wouldActivate'] ?? false) === true) {
                ++$counts['wouldActivateUsers'];
                ++$counts['safeToActivate'];
            }
            if (($item['wouldUpdateProviderToMicrosoftEntra'] ?? false) === true) {
                ++$counts['wouldUpdateProviderToMicrosoftEntra'];
            }
            if ($action === StaffEntraLinkProposedAction::SAFE_LINK_TO_EXISTING_USER) {
                ++$counts['wouldModifyExistingUserRecords'];
            }
            if (in_array($action, [
                StaffEntraLinkProposedAction::MULTIPLE_TRICAB_USERS,
                StaffEntraLinkProposedAction::ENTRA_OID_CONFLICT,
                StaffEntraLinkProposedAction::EMAIL_CONFLICT,
                StaffEntraLinkProposedAction::EMAIL_UPN_MISMATCH,
                StaffEntraLinkProposedAction::MULTIPLE_ENTRA_MATCHES,
                StaffEntraLinkProposedAction::MANUAL_REVIEW_REQUIRED,
                StaffEntraLinkProposedAction::NON_TRICAB_EMAIL,
                StaffEntraLinkProposedAction::PROVIDER_CONFLICT,
            ], true)) {
                ++$counts['conflictsExcluded'];
                if (in_array($action, [
                    StaffEntraLinkProposedAction::MULTIPLE_TRICAB_USERS,
                    StaffEntraLinkProposedAction::ENTRA_OID_CONFLICT,
                    StaffEntraLinkProposedAction::PROVIDER_CONFLICT,
                ], true)) {
                    ++$counts['manualReviewRequired'];
                }
            }
        }

        if (!$graphDirectoryAvailable) {
            $counts['graphLookupAttempted'] = 0;
            $counts['noEntraMatch'] = 0;
        }

        return $counts;
    }

    /**
     * @param list<array<string, mixed>> $mappings
     * @param array<int, array<string, mixed>> $usersById
     * @param array<string, array<string, mixed>> $entraByOid
     * @param array<string, list<array<string, mixed>>> $entraByNormalizedEmail
     * @param array<string, array<string, mixed>> $identitiesByOid
     * @param list<array<string, mixed>> $items
     * @return array<string, mixed>|null
     */
    private function findTristanReconciliation(
        array $mappings,
        array $usersById,
        array $entraByOid,
        array $entraByNormalizedEmail,
        array $identitiesByOid,
        array $items,
    ): ?array {
        $tristanMapping = null;
        foreach ($mappings as $mapping) {
            $login = strtolower((string) ($mapping['legacyLogin'] ?? ''));
            if ($login === 'tristanguinto' || (int) ($mapping['legacyWordPressUserId'] ?? 0) === 25836) {
                $tristanMapping = $mapping;
                break;
            }
        }

        $relevantUserIds = [528, 561];
        foreach ($mappings as $mapping) {
            $uid = (int) ($mapping['userId'] ?? 0);
            if (in_array($uid, $relevantUserIds, true)) {
                continue;
            }
        }

        $matchingUsers = [];
        foreach ($relevantUserIds as $userId) {
            if (isset($usersById[$userId])) {
                $matchingUsers[] = $usersById[$userId];
            }
        }

        $entra = null;
        foreach (['tristang@tricab.com', 'tristanguinto@tricab.onmicrosoft.com', 'tristanguinto@tricab.com'] as $email) {
            foreach ($entraByNormalizedEmail[strtolower($email)] ?? [] as $candidate) {
                $entra = $candidate;
                break 2;
            }
        }

        $dryRunItem = null;
        foreach ($items as $item) {
            if ((int) ($item['legacyWordPressUserId'] ?? 0) === 25836
                || strtolower((string) ($item['legacyLogin'] ?? '')) === 'tristanguinto') {
                $dryRunItem = $item;
                break;
            }
        }

        if ($tristanMapping === null && $matchingUsers === [] && $entra === null) {
            return null;
        }

        $canonical = $dryRunItem !== null
            ? [
                'userId' => $dryRunItem['canonicalUserId'],
                'reason' => implode('; ', $dryRunItem['issues'] ?? []),
                'confidence' => $dryRunItem['confidence'] ?? 'medium',
            ]
            : ($matchingUsers !== [] ? $this->recommendCanonicalUser($matchingUsers, 561) : null);

        $proposed = $dryRunItem['proposedAction'] ?? StaffEntraLinkProposedAction::MANUAL_REVIEW_REQUIRED;
        $actionLabel = match ($proposed) {
            StaffEntraLinkProposedAction::SAFE_LINK_TO_MAPPED_USER,
            StaffEntraLinkProposedAction::SAFE_LINK_TO_EXISTING_USER => 'SAFE_TO_LINK',
            StaffEntraLinkProposedAction::MULTIPLE_TRICAB_USERS => 'MANUAL_REVIEW',
            StaffEntraLinkProposedAction::ENTRA_OID_CONFLICT,
            StaffEntraLinkProposedAction::PROVIDER_CONFLICT => 'CONFLICT',
            StaffEntraLinkProposedAction::GRAPH_DIRECTORY_UNAVAILABLE => 'GRAPH_DIRECTORY_UNAVAILABLE',
            default => 'REVIEW',
        };

        return [
            'legacyWordPressUserId' => (int) ($tristanMapping['legacyWordPressUserId'] ?? 25836),
            'legacyLogin' => (string) ($tristanMapping['legacyLogin'] ?? 'tristanguinto'),
            'mappedUserId' => (int) ($tristanMapping['userId'] ?? 561),
            'entraObjectId' => $entra['objectId'] ?? ($dryRunItem['entraObjectId'] ?? null),
            'entraUpn' => $entra['userPrincipalName'] ?? ($dryRunItem['entraUpn'] ?? null),
            'entraMail' => $entra['mail'] ?? ($dryRunItem['entraMail'] ?? null),
            'matchingTriCabUsers' => array_map(static fn (array $u): array => [
                'userId' => (int) ($u['userId'] ?? 0),
                'email' => (string) ($u['emailAddress'] ?? ''),
                'status' => (string) ($u['statusCode'] ?? ''),
                'roles' => $u['roleCodes'] ?? [],
                'hasEntraLink' => (bool) ($u['hasEntraLink'] ?? false),
            ], $matchingUsers),
            'recommendedCanonicalUserId' => $canonical['userId'] ?? null,
            'recommendedReason' => $canonical['reason'] ?? null,
            'confidence' => $canonical['confidence'] ?? ($dryRunItem['confidence'] ?? 'medium'),
            'proposedAction' => $proposed,
            'proposedActionLabel' => $actionLabel,
            'note' => 'Do not automatically link Entra to the migrated row without operator review.',
        ];
    }
}
