<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Rfq\Legacy;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use TriCab\Api\Infrastructure\Cache\ArrayApplicationCache;
use TriCab\Api\Rfq\Legacy\DescriptRfqPayTermsDirectory;
use TriCab\Core\Product\Migration\Live\LegacyReadOnlyConnection;
use TriCab\Core\Rfq\Domain\Exception\RfqLookupUnavailable;

/** Pay terms from a fake `descript`: read once an hour, and a failure is not cached. */
final class DescriptRfqPayTermsDirectoryTest extends TestCase
{
    /** @param list<array<string, mixed>> $rows */
    private static function legacy(array $rows, bool $down = false): FakeDescript
    {
        return new FakeDescript($rows, $down);
    }

    public function test_the_terms_are_read_once_and_then_served_from_the_cache(): void
    {
        $legacy = self::legacy([
            ['code' => '100PP', 'name' => '100% Prepayment'],
            ['code' => 'EOM030ND', 'name' => '30 days end of month'],
            ['code' => '', 'name' => 'no code, not offered'],
        ]);
        $directory = new DescriptRfqPayTermsDirectory($legacy, new ArrayApplicationCache());

        $first = $directory->all();
        $second = $directory->all();

        self::assertSame(['100PP', 'EOM030ND'], array_map(static fn ($t) => $t->code, $first));
        self::assertSame('100% Prepayment', $second[0]->name);
        self::assertSame(1, $legacy->reads);
    }

    public function test_a_database_that_is_down_is_unavailable_and_tried_again_next_time(): void
    {
        $legacy = self::legacy([['code' => '100PP', 'name' => 'Prepay']], down: true);
        $directory = new DescriptRfqPayTermsDirectory($legacy, new ArrayApplicationCache());

        try {
            $directory->all();
            self::fail('Expected the lookup to be unavailable.');
        } catch (RfqLookupUnavailable) {
        }
        $legacy->down = false;

        self::assertCount(1, $directory->all());
        self::assertSame(2, $legacy->reads);
    }
}

/** A `descript` that counts its reads and can be switched off. */
final class FakeDescript implements LegacyReadOnlyConnection
{
    public int $reads = 0;

    /** @param list<array<string, mixed>> $rows */
    public function __construct(private readonly array $rows, public bool $down)
    {
    }

    public function query(string $sql, array $params = [], string $repository = 'unknown', string $method = 'unknown'): array
    {
        ++$this->reads;
        if ($this->down) {
            throw new RuntimeException('legacy database unreachable');
        }

        return $this->rows;
    }

    public function sourceLabel(): string
    {
        return 'fake descript';
    }
}
