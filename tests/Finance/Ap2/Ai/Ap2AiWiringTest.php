<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap2\Ai;

use DI\Container;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Config\Settings;
use TriCab\Api\Finance\Ap2\Ai\FoundryAp2Copilot;
use TriCab\Api\Finance\Ap2\Ai\FoundryAp2DraftWriter;
use TriCab\Api\Finance\Ap2\Ai\FoundryAp2InvoiceExtractor;
use TriCab\Api\Finance\Ap2\Ai\FoundryAp2MailClassifier;
use TriCab\Api\Finance\Ap2\Ai\NullAp2Copilot;
use TriCab\Api\Finance\Ap2\Ai\NullAp2DraftWriter;
use TriCab\Api\Finance\Ap2\Ai\NullAp2InvoiceExtractor;
use TriCab\Api\Finance\Ap2\Ai\NullAp2MailClassifier;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Finance\Ap2\Contract\Ap2Copilot;
use TriCab\Core\Finance\Ap2\Contract\Ap2DraftWriter;
use TriCab\Core\Finance\Ap2\Contract\Ap2InvoiceExtractor;
use TriCab\Core\Finance\Ap2\Contract\Ap2MailClassifier;

/** FINANCE_AP2_AI_ENABLED and the Foundry connection pick the Foundry implementations; anything less keeps the Nulls. */
final class Ap2AiWiringTest extends TestCase
{
    private const FOUNDRY = ['FOUNDRY_ENABLED' => 'true', 'FOUNDRY_ENDPOINT' => 'https://tricab-ai2.services.ai.azure.com', 'FOUNDRY_AUTH_MODE' => 'managed-identity', 'FOUNDRY_MODEL_DEPLOYMENT' => 'claude-sonnet-5'];

    /** @param array<string, string> $env */
    private static function container(array $env): Container
    {
        $c = TestAppFactory::create(array_merge(['FINANCE_MODULE_ENABLED' => 'true', 'FINANCE_AP2_ENABLED' => 'true'], $env))->getContainer();
        self::assertInstanceOf(Container::class, $c);

        return $c;
    }

    public function testOffByDefault(): void
    {
        foreach ([[], self::FOUNDRY, ['FINANCE_AP2_AI_ENABLED' => 'true']] as $env) {
            $c = self::container($env);
            self::assertInstanceOf(NullAp2MailClassifier::class, $c->get(Ap2MailClassifier::class));
            self::assertInstanceOf(NullAp2InvoiceExtractor::class, $c->get(Ap2InvoiceExtractor::class));
            self::assertInstanceOf(NullAp2DraftWriter::class, $c->get(Ap2DraftWriter::class));
            self::assertInstanceOf(NullAp2Copilot::class, $c->get(Ap2Copilot::class));
        }
    }

    public function testOnWithTheFoundryConnection(): void
    {
        $c = self::container(self::FOUNDRY + ['FINANCE_AP2_AI_ENABLED' => 'true', 'FINANCE_AP2_AI_COPILOT_MODEL' => 'claude-sonnet-5-5']);

        self::assertInstanceOf(FoundryAp2MailClassifier::class, $c->get(Ap2MailClassifier::class));
        self::assertInstanceOf(FoundryAp2InvoiceExtractor::class, $c->get(Ap2InvoiceExtractor::class));
        self::assertInstanceOf(FoundryAp2DraftWriter::class, $c->get(Ap2DraftWriter::class));
        self::assertInstanceOf(FoundryAp2Copilot::class, $c->get(Ap2Copilot::class));

        $settings = $c->get(Settings::class);
        self::assertInstanceOf(Settings::class, $settings);
        self::assertSame(['claude-haiku-5-5', 'claude-haiku-5-5', 'claude-haiku-5-5', 'claude-sonnet-5-5', 60], [
            $settings->financeAp2AiClassifyModel, $settings->financeAp2AiExtractModel, $settings->financeAp2AiDraftModel, $settings->financeAp2AiCopilotModel, $settings->financeAp2AiTimeoutSeconds,
        ]);
    }
}
