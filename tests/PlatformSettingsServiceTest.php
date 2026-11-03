<?php

declare(strict_types=1);

namespace TriCab\Api\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Config\Settings;
use TriCab\Api\PlatformSettings\InMemoryPlatformSettingsRepository;
use TriCab\Api\PlatformSettings\PlatformSettingsService;

final class PlatformSettingsServiceTest extends TestCase
{
    public function test_python_defaults_match_sidecar_fallback_chains(): void
    {
        $previous = $_ENV;
        try {
            $_ENV = array_replace($_ENV, [
                'RFQ_READER_MODEL' => 'azure-reader',
                'RFQ_READER_NORMALIZER_MODEL' => '',
                'RFQ_NORMALIZER_MODEL' => '',
                'RFQ_TABLE_SHAPE_MODEL' => '',
                'RFQ_LLM_MODEL' => 'provider-configured',
            ]);
            $service = new PlatformSettingsService(new InMemoryPlatformSettingsRepository(), $this->settings('', 'claude-haiku-5-5'));
            /** @var array{ai: array{tasks: array<string, string>}} $defaults */
            $defaults = $service->defaults();
            $tasks = $defaults['ai']['tasks'];
            self::assertSame('azure-reader', $tasks['rfqReaderNormalizerModel']);
            self::assertSame('provider-configured', $tasks['rfqNormalizerModel']);
            self::assertSame('provider-configured', $tasks['rfqTableShapeModel']);
        } finally {
            $_ENV = $previous;
        }
    }

    public function test_first_write_freezes_php_baseline_and_runtime_uses_it_after_env_changes(): void
    {
        $repository = new InMemoryPlatformSettingsRepository();
        $initial = new PlatformSettingsService($repository, $this->settings('baseline@example.com', 'claude-haiku-5-5'));
        $initial->replace(0, ['notifications' => ['nprMailTestRecipient' => 'override@example.com']], 1);
        $changed = new PlatformSettingsService($repository, $this->settings('changed@example.com', 'claude-opus-4-8'));
        /** @var array{defaults: array{notifications: array<string, string>, ai: array{tasks: array<string, string>}}} $snapshot */
        $snapshot = $changed->snapshot();
        self::assertSame('baseline@example.com', $snapshot['defaults']['notifications']['nprMailTestRecipient']);
        self::assertSame('claude-haiku-5-5', $snapshot['defaults']['ai']['tasks']['rfqMailClassifierModel']);
        /** @var array{notifications: array<string, string>, ai: array{tasks: array<string, string>}} $runtime */
        $runtime = $changed->runtimeOverrides();
        self::assertSame('override@example.com', $runtime['notifications']['nprMailTestRecipient']);
        self::assertSame('claude-haiku-5-5', $runtime['ai']['tasks']['rfqMailClassifierModel']);
    }

    public function test_null_task_inherits_registered_compatible_platform_default(): void
    {
        $repository = new InMemoryPlatformSettingsRepository();
        $service = new PlatformSettingsService($repository, $this->settings('', 'claude-haiku-5-5'));
        /** @var array{sections: array{ai: array{tasks: array<string, string>}}, sources: array{ai: array{tasks: array<string, string>}}} $snapshot */
        $snapshot = $service->replace(0, ['ai' => [
            'defaultModel' => 'claude-haiku-5-5',
            'tasks' => ['rfqMailClassifierModel' => null],
        ]], 1);
        self::assertSame('claude-haiku-5-5', $snapshot['sections']['ai']['tasks']['rfqMailClassifierModel']);
        self::assertSame('platform-default', $snapshot['sources']['ai']['tasks']['rfqMailClassifierModel']);
        /** @var array{ai: array{tasks: array<string, string>}} $runtime */
        $runtime = $service->runtimeOverrides();
        self::assertSame('claude-haiku-5-5', $runtime['ai']['tasks']['rfqMailClassifierModel']);
    }

    public function test_malformed_model_catalog_is_validation_error(): void
    {
        $service = new PlatformSettingsService(new InMemoryPlatformSettingsRepository(), $this->settings('', 'claude-haiku-5-5'));
        $this->expectException(InvalidArgumentException::class);
        $service->replace(0, ['ai' => ['models' => 'wrong type']], 1);
    }

    private function settings(string $recipient, string $model): Settings
    {
        return Settings::fromEnvironment([
            'APP_ENV' => 'development',
            'USER_REPOSITORY' => 'memory',
            'GUEST_EMAIL_PROVIDER' => 'development',
            'GUEST_JWT_SECRET' => 'local-development-guest-jwt-secret-32b',
            'NPR_MAIL_TEST_RECIPIENT' => $recipient,
            'RFQ_MAIL_CLASSIFIER_MODEL' => $model,
        ]);
    }
}
