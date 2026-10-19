<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\CableFit;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TriCab\Core\CableFit\Application\ResearchSourcePolicy;
use TriCab\Core\CableFit\Domain\ResearchFinding;

/** Which URLs belong to an enabled source, and which findings count as recent. */
final class ResearchSourcePolicyTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function urls(): array
    {
        return [
            'the domain itself' => ['https://iec.ch/standard', true],
            'www subdomain' => ['https://www.iec.ch/standard', true],
            'deeper subdomain' => ['http://webstore.shop.iec.ch/a?b=c', true],
            'host case ignored' => ['https://WWW.IEC.CH/x', true],
            'trailing dot' => ['https://iec.ch./x', true],
            'second source' => ['https://prysmian.com/xlpe.pdf', true],
            'lookalike suffix' => ['https://iec.ch.evil.com/x', false],
            'lookalike prefix' => ['https://eviliec.ch/x', false],
            'domain in the path' => ['https://evil.com/iec.ch', false],
            'domain in the query' => ['https://evil.com/?u=https://iec.ch', false],
            'userinfo trick' => ['https://iec.ch@evil.com/x', false],
            'userinfo with source host after' => ['https://evil.com@iec.ch/x', true],
            'other tld' => ['https://iec.com/x', false],
            'disabled source' => ['https://nexans.com/x', false],
            'not http' => ['ftp://iec.ch/x', false],
            'javascript' => ['javascript:alert(1)', false],
            'no scheme' => ['iec.ch/x', false],
            'garbage' => ['not a url', false],
            'empty' => ['', false],
        ];
    }

    #[DataProvider('urls')]
    public function test_only_enabled_domains_and_their_subdomains_are_allowed(string $url, bool $allowed): void
    {
        self::assertSame($allowed, ResearchSourcePolicy::allows($url, ['iec.ch', 'prysmian.com']), $url);
    }

    public function test_no_domains_allow_nothing(): void
    {
        self::assertFalse(ResearchSourcePolicy::allows('https://iec.ch/x', []));
        self::assertFalse(ResearchSourcePolicy::allows('https://iec.ch/x', ['']));
    }

    /**
     * @return array<string, array{0: string|null, 1: bool}>
     */
    public static function windows(): array
    {
        // The run was requested 2026-10-19 15:30 UTC, so the window starts 2024-10-19.
        return [
            'exactly 24 months before' => ['2024-10-19', true],
            'a day too old' => ['2024-10-18', false],
            'a day inside' => ['2024-10-20', true],
            'yesterday' => ['2026-10-18', true],
            'the same day' => ['2026-10-19', true],
            'in the future' => ['2026-11-30', true],
            'month, starting before the cut-off' => ['2024-10', false],
            'month, starting after the cut-off' => ['2024-11', true],
            'year, starting before the cut-off' => ['2024', false],
            'next year' => ['2025', true],
            'this year' => ['2026', true],
            'long ago' => ['2010-06-01', false],
            'undated' => [null, false],
        ];
    }

    #[DataProvider('windows')]
    public function test_within_recent_window_boundaries(?string $publishedOn, bool $recent): void
    {
        $finding = new ResearchFinding('f', 'p', 'c', 'r', 'https://iec.ch/x', 't', null, $publishedOn, $publishedOn === null ? 'undated' : 'page_age', 'high', 0);

        self::assertSame($recent, $finding->withinRecentWindow(new DateTimeImmutable('2026-10-19T15:30:00Z')), (string) $publishedOn);
    }

    public function test_the_window_is_counted_from_the_run_not_from_now(): void
    {
        $finding = new ResearchFinding('f', 'p', 'c', 'r', 'https://iec.ch/x', 't', null, '2023-01-01', 'page_age', 'high', 0);

        self::assertTrue($finding->withinRecentWindow(new DateTimeImmutable('2024-12-31T00:00:00Z')));
        self::assertFalse($finding->withinRecentWindow(new DateTimeImmutable('2025-01-02T00:00:00Z')));
    }

    public function test_a_leap_day_cut_off_does_not_overflow(): void
    {
        $finding = new ResearchFinding('f', 'p', 'c', 'r', 'https://iec.ch/x', 't', null, '2022-03-01', 'page_age', 'high', 0);

        // 24 months before 2024-02-29 is 2022-02-28 or 2022-03-01 depending on rounding; either way 2022-03-01 is inside.
        self::assertTrue($finding->withinRecentWindow(new DateTimeImmutable('2024-02-29T10:00:00Z')));
    }
}
