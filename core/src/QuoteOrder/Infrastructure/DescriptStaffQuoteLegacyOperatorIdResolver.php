<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Infrastructure;

use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Product\Migration\Live\LegacyReadOnlyConnection;
use TriCab\Core\QuoteOrder\Contract\QuoteLegacyOperatorIdResolver;
use TriCab\Core\QuoteOrder\Domain\Mapping\LegacyQuoteLineTypeDefaults;

/**
 * Staff actor → tqoth.sa_op via MySQL descript (desc_type=36).
 * Matches legacy UserManagement: desc_code = staff login / user_login.
 *
 * Portal (customerGenerated) is out of first-write scope — returns null.
 */
final class DescriptStaffQuoteLegacyOperatorIdResolver implements QuoteLegacyOperatorIdResolver
{
    public function __construct(
        private readonly LegacyReadOnlyConnection $legacy,
    ) {
    }

    public function resolveRaisedByOperatorId(AuthenticatedUser $actor, bool $customerGenerated): ?int
    {
        if ($customerGenerated) {
            return null;
        }

        $candidates = $this->loginCandidates($actor->emailAddress);
        if ($candidates === []) {
            return null;
        }

        $placeholders = [];
        $params = [];
        foreach (array_values($candidates) as $i => $candidate) {
            $key = 'c' . $i;
            $placeholders[] = ':' . $key;
            $params[$key] = $candidate;
        }

        $sql = 'SELECT desc_id FROM tc.descript'
            . ' WHERE desc_type = ' . LegacyQuoteLineTypeDefaults::STAFF_DESCRIPT_TYPE
            . ' AND UPPER(TRIM(desc_code)) IN (' . implode(',', array_map(
                static fn (string $p): string => 'UPPER(TRIM(' . $p . '))',
                $placeholders,
            )) . ')'
            . ' LIMIT 3';

        $rows = $this->legacy->query(
            $sql,
            $params,
            self::class,
            'resolveRaisedByOperatorId',
        );

        $ids = [];
        foreach ($rows as $row) {
            $id = (int) ($row['desc_id'] ?? 0);
            if ($id > 0) {
                $ids[$id] = true;
            }
        }

        if (count($ids) !== 1) {
            return null;
        }

        return (int) array_key_first($ids);
    }

    /**
     * @return list<string>
     */
    private function loginCandidates(string $emailAddress): array
    {
        $email = strtolower(trim($emailAddress));
        if ($email === '' || !str_contains($email, '@')) {
            return [];
        }
        $local = trim(explode('@', $email, 2)[0]);
        $out = [];
        foreach ([$email, $local, strtoupper($local), strtolower($local)] as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '') {
                $out[$candidate] = $candidate;
            }
        }

        return array_values($out);
    }
}
