<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Rfq\Parser;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use TriCab\Core\Rfq\Domain\Exception\InvalidRfqValue;
use TriCab\Core\Rfq\Parser\ParseSeal;
use TriCab\Core\Rfq\Storage\PythonJson;
use TriCab\Core\Tests\Rfq\Fixtures\FixtureRfqParserClient;

/** Parity plan D3: what Match Text hands the browser, and what Save as Draft accepts back. */
final class ParseSealTest extends TestCase
{
    private const EMAIL = 'rep@tricab.com';

    private static function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time);
    }

    public function test_the_rows_come_back_byte_for_byte(): void
    {
        $seal = new ParseSeal('token');
        $rows = FixtureRfqParserClient::lines();
        $token = $seal->seal(self::EMAIL, self::at('2026-09-28T02:00:00Z'), $rows, ['degraded' => true]);
        $opened = $seal->open($token, 'Rep@TriCab.com', self::at('2026-09-28T03:00:00Z'));

        self::assertSame(PythonJson::encode($rows), PythonJson::encode($opened['rows']));
        self::assertSame(['degraded' => true], $opened['parse']);
        self::assertStringNotContainsString('rep@tricab.com', (string) gzinflate((string) base64_decode(strtr(explode('.', $token)[0], '-_', '+/'))), 'no address in the seal');
    }

    public function test_a_changed_row_is_refused(): void
    {
        $seal = new ParseSeal('token');
        $token = $seal->seal(self::EMAIL, self::at('2026-09-28T02:00:00Z'), [['selected_product_code' => 'A']], []);
        $signature = explode('.', $token)[1];
        $forged = rtrim(strtr(base64_encode((string) gzdeflate(PythonJson::encode([
            'v' => ParseSeal::VERSION, 'who' => 'anyone', 'issued' => 1_790_000_000, 'parse' => [], 'rows' => [['selected_product_code' => 'B']],
        ]))), '+/', '-_'), '=');

        $this->expectException(InvalidRfqValue::class);
        $seal->open($forged . '.' . $signature, self::EMAIL, self::at('2026-09-28T03:00:00Z'));
    }

    public function test_another_servers_seal_is_refused(): void
    {
        $token = (new ParseSeal('other'))->seal(self::EMAIL, self::at('2026-09-28T02:00:00Z'), [], []);

        $this->expectException(InvalidRfqValue::class);
        (new ParseSeal('token'))->open($token, self::EMAIL, self::at('2026-09-28T02:00:00Z'));
    }

    public function test_it_expires_after_twelve_hours(): void
    {
        $seal = new ParseSeal('token');
        $token = $seal->seal(self::EMAIL, self::at('2026-09-28T02:00:00Z'), [], []);
        $seal->open($token, self::EMAIL, self::at('2026-09-28T13:59:00Z'));

        $this->expectException(InvalidRfqValue::class);
        $this->expectExceptionMessage('12 hours');
        $seal->open($token, self::EMAIL, self::at('2026-09-28T14:01:00Z'));
    }

    public function test_garbage_is_refused_not_thrown(): void
    {
        $seal = new ParseSeal('token');
        foreach (['', 'x', 'x.y', 'a.b.c', '!!!.???'] as $token) {
            try {
                $seal->open($token, self::EMAIL, self::at('2026-09-28T02:00:00Z'));
                self::fail('accepted ' . $token);
            } catch (InvalidRfqValue) {
                self::addToAssertionCount(1);
            }
        }
    }
}
