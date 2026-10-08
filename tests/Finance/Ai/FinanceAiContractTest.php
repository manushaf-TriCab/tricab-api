<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ai;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Finance\Shared\Ai\FinanceAiContract;
use TriCab\Core\Finance\Shared\Ai\FinanceAiFailure;

/**
 * The two ends of `finance-ai.v1` are written twice, in PHP and in Python. This reads the
 * Python file and fails when they drift: the version, the error codes, the setting that
 * holds the token, the correlation pattern, the deadline bounds and the routes.
 */
final class FinanceAiContractTest extends TestCase
{
    private static function python(): string
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/core/python/finance_ai/contract.py');
        self::assertIsString($source);

        return $source;
    }

    public function test_the_version_matches(): void
    {
        self::assertStringContainsString('CONTRACT = "' . FinanceAiContract::VERSION . '"', self::python());
    }

    public function test_the_error_codes_are_the_same_list(): void
    {
        if (preg_match('/CODES = \((.*?)\)/s', self::python(), $match) !== 1) {
            self::fail('contract.py has no CODES list.');
        }
        preg_match_all('/"([a-z_]+)"/', $match[1], $codes);

        self::assertSame(FinanceAiFailure::CODES, $codes[1]);
    }

    public function test_the_correlation_pattern_and_the_deadline_bounds_match(): void
    {
        $python = self::python();

        self::assertStringContainsString('re.compile(r"^[A-Za-z0-9][A-Za-z0-9._-]{7,63}$")', $python);
        self::assertSame('/^[A-Za-z0-9][A-Za-z0-9._-]{7,63}$/', FinanceAiContract::CORRELATION_PATTERN);
        self::assertStringContainsString('MIN_DEADLINE_MS = ' . number_format(FinanceAiContract::MIN_DEADLINE_MS, 0, '', '_'), $python);
        self::assertStringContainsString('MAX_DEADLINE_MS = ' . number_format(FinanceAiContract::MAX_DEADLINE_MS, 0, '', '_'), $python);
    }

    public function test_the_routes_and_the_token_setting_match(): void
    {
        $python = self::python();

        self::assertStringContainsString('@v1.get("' . FinanceAiContract::PATH_HEALTH . '")', $python);
        self::assertStringContainsString('@v1.post("' . FinanceAiContract::PATH_COMPLETE . '<purpose_name>")', $python);
        self::assertStringContainsString('TOKEN_SETTING = "FINANCE_AI_INTERNAL_TOKEN"', $python);
    }

    public function test_the_purpose_pattern_accepts_what_the_registry_accepts(): void
    {
        $registry = file_get_contents(dirname(__DIR__, 3) . '/core/python/finance_ai/registry.py');
        self::assertIsString($registry);
        self::assertStringContainsString('ch.islower() or ch.isdigit() or ch in "-_."', $registry);

        foreach (['invoice-extract', 'ar.classify', 'a_b-1', 'x'] as $name) {
            self::assertSame(1, preg_match(FinanceAiContract::PURPOSE_PATTERN, $name), $name);
        }
        foreach (['', 'Upper', 'has space', '../x', '-lead'] as $name) {
            self::assertSame(0, preg_match(FinanceAiContract::PURPOSE_PATTERN, $name), $name);
        }
    }
}
