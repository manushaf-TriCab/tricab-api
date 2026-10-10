<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Config\Settings;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Finance\Ap\ApAiService;
use TriCab\Core\Finance\Ap\ApExtraction;
use TriCab\Core\Finance\Ap\Service\ApGrading;
use TriCab\Core\Finance\Ap\Service\ApInvoiceService;

/** The three AP AI settings, and the one grader (`ApExtraction::grade` is `ApGrading::grade`). */
final class ApAiSettingsTest extends TestCase
{
    private const ENV = ['APP_ENV' => 'development', 'USER_REPOSITORY' => 'memory', 'DB_AUTH_MODE' => 'sql-login', 'GUEST_JWT_SECRET' => 'local-development-guest-jwt-secret-32b'];

    /**
     * @param array<string, string> $env
     */
    private static function settings(array $env = []): Settings
    {
        return Settings::fromEnvironment($env + self::ENV);
    }

    public function test_the_defaults(): void
    {
        $settings = self::settings();
        self::assertTrue($settings->financeApSuggestReplies);
        self::assertSame(0.80, $settings->financeApConfidenceThreshold);
        self::assertSame([], $settings->financeApInternalEmailDomains);
    }

    public function test_suggest_replies_parses_like_the_source(): void
    {
        foreach (['0', 'false', 'No', ' FALSE '] as $off) {
            self::assertFalse(self::settings(['FINANCE_AP_SUGGEST_REPLIES' => $off])->financeApSuggestReplies, $off);
        }
        foreach (['1', 'true', 'yes', '', 'anything'] as $on) {
            self::assertTrue(self::settings(['FINANCE_AP_SUGGEST_REPLIES' => $on])->financeApSuggestReplies, $on);
        }
    }

    public function test_the_threshold_is_validated_to_zero_to_one(): void
    {
        self::assertSame(0.65, self::settings(['FINANCE_AP_CONFIDENCE_THRESHOLD' => '0.65'])->financeApConfidenceThreshold);
        self::assertSame(0.0, self::settings(['FINANCE_AP_CONFIDENCE_THRESHOLD' => '0'])->financeApConfidenceThreshold);
        self::assertSame(1.0, self::settings(['FINANCE_AP_CONFIDENCE_THRESHOLD' => '1'])->financeApConfidenceThreshold);
        foreach (['1.5', '-0.1', 'high'] as $bad) {
            try {
                self::settings(['FINANCE_AP_CONFIDENCE_THRESHOLD' => $bad]);
                self::fail($bad . ' must be refused');
            } catch (InvalidArgumentException $refused) {
                self::assertStringContainsString('FINANCE_AP_CONFIDENCE_THRESHOLD', $refused->getMessage());
            }
        }
    }

    public function test_internal_domains_are_a_trimmed_lower_case_list_without_the_at(): void
    {
        self::assertSame(['tricab.com', 'example.test'], self::settings(['FINANCE_AP_INTERNAL_EMAIL_DOMAINS' => ' @TriCab.com, example.test ,'])->financeApInternalEmailDomains);
    }

    public function test_the_threshold_reaches_the_invoice_service_and_the_ai_service(): void
    {
        $app = TestAppFactory::create(['FINANCE_MODULE_ENABLED' => 'true', 'FINANCE_AP_ENABLED' => 'true', 'FINANCE_AP_CONFIDENCE_THRESHOLD' => '0.5']);
        $ai = $app->getContainer()->get(ApAiService::class);
        $invoices = $app->getContainer()->get(ApInvoiceService::class);
        self::assertIsObject($ai);
        self::assertIsObject($invoices);
        $read = static function (object $service, string $property): mixed {
            return (new \ReflectionProperty($service, $property))->getValue($service);
        };
        self::assertSame(0.5, $read($ai, 'confidenceThreshold'));
        self::assertSame(0.5, $read($invoices, 'confidenceThreshold'));
        self::assertNotNull($read($invoices, 'vendors'), 'capture and edit re-match vendors');
        $off = TestAppFactory::create(['FINANCE_MODULE_ENABLED' => 'true', 'FINANCE_AP_ENABLED' => 'true', 'FINANCE_AP_SUGGEST_REPLIES' => 'no']);
        $offAi = $off->getContainer()->get(ApAiService::class);
        self::assertIsObject($offAi);
        self::assertFalse($read($offAi, 'suggestRepliesEnabled'));
    }

    public function test_extraction_and_the_register_grade_alike(): void
    {
        $cases = [
            ['net_amount' => 0.1, 'tax_amount' => 0.2, 'total_amount' => 0.33],
            ['net_amount' => 100, 'tax_amount' => 10, 'total_amount' => 110.02],
            ['net_amount' => 100, 'tax_amount' => 10, 'total_amount' => 110.03],
            ['net_amount' => 19.99, 'tax_amount' => 2.0, 'total_amount' => 22.01, 'extraction_confidence' => 0.745],
            ['net_amount' => 1.005, 'tax_amount' => 1.005, 'total_amount' => 2.0, 'extraction_confidence' => 0.795],
            ['invoice_no' => '', 'extraction_confidence' => 'x'],
        ];
        foreach ($cases as $index => $case) {
            $fields = $case + ScriptedApAiClient::invoiceFields();
            self::assertSame(ApGrading::grade($fields), ApExtraction::grade($fields), 'case ' . $index);
        }
        self::assertSame('Low extraction confidence (80%)', ApExtraction::grade(['extraction_confidence' => 0.795, 'invoice_no' => 'a', 'vendor' => 'b', 'total_amount' => 1])[1]);
    }
}
