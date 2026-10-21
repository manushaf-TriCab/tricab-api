<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\PlatformSettings;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use TriCab\Api\PlatformSettings\PlatformModelCatalog;

final class PlatformModelCatalogTest extends TestCase
{
    public function test_existing_model_capabilities_are_combined_from_current_tasks(): void
    {
        $metadata = PlatformModelCatalog::metadata(['ai' => ['tasks' => [
            'productFinderModel' => 'provider/model', 'visionOcrPrimaryModel' => 'provider/model',
        ]]]);
        self::assertCount(1, $metadata['models']);
        PlatformModelCatalog::validateSelection('visionOcrPrimaryModel', 'provider/model', $metadata);
        self::assertContains('vision', $metadata['models'][0]['capabilities']);
    }

    public function test_chat_model_cannot_replace_decisions_model(): void
    {
        $metadata = PlatformModelCatalog::metadata(['ai' => ['tasks' => [
            'productFinderModel' => 'provider/chat', 'ar_classify' => 'typesafe/decision',
        ]]]);
        $this->expectException(InvalidArgumentException::class);
        PlatformModelCatalog::validateSelection('ar_classify', 'provider/chat', $metadata);
    }

    public function test_provider_mismatch_is_rejected(): void
    {
        $metadata = PlatformModelCatalog::metadata(['ai' => ['tasks' => [
            'productFinderModel' => 'provider/chat', 'foundryModelDeployment' => 'deployment',
        ]]]);
        $this->expectException(InvalidArgumentException::class);
        PlatformModelCatalog::validateSelection('foundryModelDeployment', 'provider/chat', $metadata);
    }

    public function test_new_registered_compatible_model_can_be_selected(): void
    {
        $metadata = PlatformModelCatalog::metadata(['ai' => ['tasks' => ['productFinderModel' => 'old/model']]], [
            ['id' => 'new/model', 'provider' => 'openrouter', 'capabilities' => ['text']],
        ]);
        PlatformModelCatalog::validateSelection('productFinderModel', 'new/model', $metadata);
        self::assertSame('new/model', $metadata['models'][0]['id']);
    }

    public function test_credentials_are_not_accepted_in_catalog(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PlatformModelCatalog::validateModels([['id' => 'model', 'provider' => 'foundry', 'capabilities' => ['text'], 'apiKey' => 'secret']]);
    }
}
