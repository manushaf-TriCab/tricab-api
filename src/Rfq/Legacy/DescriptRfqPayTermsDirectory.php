<?php

declare(strict_types=1);

namespace TriCab\Api\Rfq\Legacy;

use Throwable;
use TriCab\Api\Infrastructure\Cache\ApplicationCache;
use TriCab\Core\Product\Migration\Live\LegacyReadOnlyConnection;
use TriCab\Core\Rfq\Application\DTO\RfqPayTerm;
use TriCab\Core\Rfq\Contract\RfqPayTermsDirectory;
use TriCab\Core\Rfq\Domain\Exception\RfqLookupUnavailable;

/**
 * 4js payment terms from the legacy `descript` table (desc_type 33), read
 * only: the source's `list_pay_terms`, cached for an hour as the source's
 * `TRICAB_PAY_TERMS_CACHE_SECONDS` default does, so the legacy database is
 * asked about once an hour however many editors open.
 *
 * A failed read is not cached: the next request tries again.
 */
final class DescriptRfqPayTermsDirectory implements RfqPayTermsDirectory
{
    public const CACHE_KEY = 'rfq.lookups.pay-terms.v1';
    public const CACHE_SECONDS = 3600;

    private const SQL = <<<'SQL'
        SELECT TRIM(desc_code) AS code, TRIM(desc_name) AS name FROM tc.descript
        WHERE desc_type = 33 ORDER BY desc_code
        SQL;

    public function __construct(
        private readonly LegacyReadOnlyConnection $legacy,
        private readonly ApplicationCache $cache,
    ) {
    }

    public function all(): array
    {
        $cached = $this->cached();
        if ($cached !== null) {
            return $cached;
        }

        try {
            $rows = $this->legacy->query(self::SQL, [], self::class, 'all');
        } catch (Throwable $failure) {
            throw new RfqLookupUnavailable('pay terms', $failure);
        }

        $pairs = [];
        foreach ($rows as $row) {
            $code = is_string($row['code'] ?? null) ? $row['code'] : '';
            if ($code !== '') {
                $pairs[] = [$code, is_string($row['name'] ?? null) ? $row['name'] : ''];
            }
        }
        try {
            $this->cache->set(self::CACHE_KEY, $pairs, self::CACHE_SECONDS);
        } catch (Throwable) {
            // A cache that is down costs a legacy read next time, not the lookup.
        }

        return self::terms($pairs);
    }

    /** @return list<RfqPayTerm>|null */
    private function cached(): ?array
    {
        try {
            $pairs = $this->cache->get(self::CACHE_KEY);
        } catch (Throwable) {
            return null;
        }
        if (!is_array($pairs)) {
            return null;
        }
        foreach ($pairs as $pair) {
            if (!is_array($pair) || !is_string($pair[0] ?? null) || !is_string($pair[1] ?? null)) {
                return null;
            }
        }

        /** @var list<array{0: string, 1: string}> $pairs */
        return self::terms($pairs);
    }

    /**
     * @param list<array{0: string, 1: string}> $pairs
     *
     * @return list<RfqPayTerm>
     */
    private static function terms(array $pairs): array
    {
        return array_map(static fn (array $pair): RfqPayTerm => new RfqPayTerm($pair[0], $pair[1]), $pairs);
    }
}
