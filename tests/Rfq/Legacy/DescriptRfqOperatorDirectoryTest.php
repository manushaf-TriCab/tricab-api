<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Rfq\Legacy;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use TriCab\Api\Rfq\Legacy\DescriptRfqOperatorDirectory;
use TriCab\Core\Product\Migration\Live\LegacyReadOnlyConnection;

/**
 * The operator lookup's rules, against a fake `descript`: aliases, one match
 * only, TriCab addresses only, and never an exception.
 */
final class DescriptRfqOperatorDirectoryTest extends TestCase
{
    /** @param array<string, list<string>> $codes login => matching codes */
    private static function legacy(array $codes, bool $down = false): LegacyReadOnlyConnection
    {
        return new class ($codes, $down) implements LegacyReadOnlyConnection {
            /** @var list<string> */
            public array $asked = [];

            /** @param array<string, list<string>> $codes */
            public function __construct(private readonly array $codes, private readonly bool $down)
            {
            }

            public function query(string $sql, array $params = [], string $repository = 'unknown', string $method = 'unknown'): array
            {
                if ($this->down) {
                    throw new RuntimeException('legacy database unreachable');
                }
                $login = (string) ($params['login'] ?? '');
                $this->asked[] = $login;

                return array_map(static fn (string $c): array => ['code' => $c], $this->codes[$login] ?? []);
            }

            public function sourceLabel(): string
            {
                return 'fake descript';
            }
        };
    }

    public function test_the_email_login_resolves_to_its_operator_code(): void
    {
        self::assertSame('SCOTTH', (new DescriptRfqOperatorDirectory(self::legacy(['scotth' => ['SCOTTH']])))->codeFor(' ScottH@TriCab.com '));
    }

    public function test_the_source_aliases_apply(): void
    {
        $legacy = self::legacy(['jeffe' => ['JEFFE']]);

        self::assertSame('JEFFE', (new DescriptRfqOperatorDirectory($legacy))->codeFor('jeffevans@tricab.com'));
    }

    public function test_no_match_or_two_matches_is_no_answer(): void
    {
        $directory = new DescriptRfqOperatorDirectory(self::legacy(['twin' => ['TWIN', 'TWIN2']]));

        self::assertNull($directory->codeFor('twin@tricab.com'));
        self::assertNull($directory->codeFor('nobody@tricab.com'));
    }

    public function test_an_external_address_is_never_looked_up(): void
    {
        $legacy = self::legacy(['buyer' => ['BUYER']]);

        self::assertNull((new DescriptRfqOperatorDirectory($legacy))->codeFor('buyer@customer.com.au'));
    }

    public function test_a_database_that_is_down_answers_null_rather_than_failing_the_save(): void
    {
        self::assertNull((new DescriptRfqOperatorDirectory(self::legacy([], down: true)))->codeFor('scotth@tricab.com'));
    }
}
