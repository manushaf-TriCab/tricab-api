<?php

declare(strict_types=1);

namespace TriCab\Api\Rfq\Legacy;

use Throwable;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Product\Migration\Live\LegacyReadOnlyConnection;
use TriCab\Core\Rfq\Contract\RfqOperatorDirectory;
use TriCab\Core\Rfq\Storage\SalesRepAttribution;

/**
 * 4js operator codes from the legacy `descript` table (desc_type 36), read
 * only: the source's `find_operator_by_login`, with the platform's staff
 * mapping in front of it.
 *
 * Logins are tried in order: the person's `LegacyUserLogin` in
 * `migration.LegacyStaffUserMappings` (as `AzureLinkedDescriptQuoteLegacyOperatorIdResolver`
 * does for quotes), then the local part of a TriCab address through the
 * source's alias table. A login answers only when exactly one operator code
 * matches it.
 *
 * Never throws. A database that is down answers null, and the save goes on
 * with the author it had: the source's rule that a lookup hiccup must not cost
 * the rep their draft.
 */
final class DescriptRfqOperatorDirectory implements RfqOperatorDirectory
{
    /**
     * Staff whose 4js login differs from their email's local part. The
     * source's `_OPERATOR_LOGIN_ALIASES`, from the website's
     * `UserManagement::getTricabData4J`.
     */
    public const LOGIN_ALIASES = [
        'jeffevans' => 'jeffe',
        'feliciagu' => 'feliciag',
    ];

    public function __construct(
        private readonly LegacyReadOnlyConnection $legacy,
        private readonly ?SqlConnection $azure = null,
    ) {
    }

    public function codeFor(string $email): ?string
    {
        foreach ($this->logins($email) as $login) {
            $code = $this->lookup($login);
            if ($code !== null) {
                return $code;
            }
        }

        return null;
    }

    /** @return list<string> lower-cased, most authoritative first */
    private function logins(string $email): array
    {
        $email = strtolower(trim($email));
        $logins = [];
        if ($this->azure !== null && $email !== '') {
            try {
                $rows = $this->azure->fetchAll(
                    'SELECT TOP (3) [LegacyUserLogin] FROM [migration].[LegacyStaffUserMappings]
                     WHERE [NormalizedEmailAddress] = :email ORDER BY [LegacyStaffUserMappingId] DESC',
                    ['email' => $email],
                );
                foreach ($rows as $row) {
                    $login = is_string($row['LegacyUserLogin'] ?? null) ? strtolower(trim($row['LegacyUserLogin'])) : '';
                    if ($login !== '') {
                        $logins[] = $login;
                    }
                }
            } catch (Throwable) {
                // No mapping table here: the email's login still answers.
            }
        }
        $local = SalesRepAttribution::login($email);
        if ($local !== '') {
            $logins[] = self::LOGIN_ALIASES[$local] ?? $local;
        }

        return array_values(array_unique($logins));
    }

    private function lookup(string $login): ?string
    {
        try {
            $rows = $this->legacy->query(
                'SELECT DISTINCT UPPER(TRIM(desc_code)) AS code FROM tc.descript
                 WHERE desc_type = 36 AND LOWER(TRIM(desc_code)) = :login LIMIT 2',
                ['login' => $login],
                self::class,
                'codeFor',
            );
        } catch (Throwable) {
            return null;
        }
        if (count($rows) !== 1) {
            return null;
        }
        $code = $rows[0]['code'] ?? null;

        return is_string($code) && $code !== '' ? $code : null;
    }
}
