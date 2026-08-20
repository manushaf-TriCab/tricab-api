<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\StaffEntraLink;

/**
 * Builds a stable fingerprint over mapping + identity + Entra directory state.
 */
final class StaffEntraLinkSnapshotFingerprint
{
    /**
     * @param list<array<string, mixed>> $mappings
     * @param array<int, array<string, mixed>> $usersById
     * @param array<string, array<string, mixed>> $entraByOid
     * @param array<string, array<string, mixed>> $identitiesByOid
     */
    public function fromState(
        array $mappings,
        array $usersById,
        array $entraByOid,
        array $identitiesByOid,
        string $expectedTenantId,
    ): string {
        $parts = [$expectedTenantId, 'mappingCount=' . count($mappings)];
        usort($mappings, static fn (array $a, array $b): int => ($a['legacyWordPressUserId'] ?? 0) <=> ($b['legacyWordPressUserId'] ?? 0));
        foreach ($mappings as $mapping) {
            $userId = (int) ($mapping['userId'] ?? 0);
            $user = $usersById[$userId] ?? [];
            $parts[] = implode('|', [
                (string) ($mapping['legacyWordPressUserId'] ?? ''),
                (string) ($mapping['legacyLogin'] ?? ''),
                (string) $userId,
                strtolower((string) ($user['normalizedEmailAddress'] ?? $mapping['normalizedEmailAddress'] ?? '')),
                (string) ($user['statusCode'] ?? ''),
                implode(',', $user['roleCodes'] ?? []),
                (string) count($user['externalProviders'] ?? []),
            ]);
        }
        ksort($entraByOid);
        foreach ($entraByOid as $oid => $entra) {
            $parts[] = 'entra:' . $oid . ':' . ($entra['userPrincipalName'] ?? '') . ':' . (($entra['accountEnabled'] ?? false) ? '1' : '0');
        }
        ksort($identitiesByOid);
        foreach ($identitiesByOid as $oid => $identity) {
            $parts[] = 'link:' . $oid . ':' . (string) ($identity['userId'] ?? '');
        }

        return hash('sha256', implode("\n", $parts));
    }
}
