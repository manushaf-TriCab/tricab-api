<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Assistant;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use TriCab\Api\Assistant\Infrastructure\Prompt\MarkdownPromptRepository;

final class MarkdownPromptRepositoryTest extends TestCase
{
    public function test_loads_an_explicit_versioned_markdown_prompt(): void
    {
        $repository = new MarkdownPromptRepository(dirname(__DIR__, 2) . '/PROMPTS');

        $prompt = $repository->get('product-assistant-query', 'v1');

        self::assertSame('product-assistant-query-v1', $prompt->id());
        self::assertStringContainsString('Fire Resistant', $prompt->content);
        self::assertStringContainsString('deterministic', $prompt->content);
    }

    public function test_the_response_prompt_keeps_core_wording_and_tool_internals_out_of_the_agent_calls_and_replies(): void
    {
        $repository = new MarkdownPromptRepository(dirname(__DIR__, 2) . '/PROMPTS');

        $content = $repository->get('product-assistant-response', 'v11')->content;

        self::assertStringContainsString('Do not turn "4C+E" into `coreCount: 4`', $content);
        self::assertStringContainsString('## Keep the workings out of the reply', $content);
        self::assertStringContainsString('isCurrent: false', $content);
        self::assertStringContainsString("the finder's earlier default", $content);
        // v10 stays on disk unchanged for stored responses that refer to it.
        self::assertStringNotContainsString('Keep the workings out', $repository->get('product-assistant-response', 'v10')->content);
    }

    public function test_rejects_unversioned_or_path_traversal_names(): void
    {
        $repository = new MarkdownPromptRepository(dirname(__DIR__, 2) . '/PROMPTS');

        $this->expectException(RuntimeException::class);
        $repository->get('../product-assistant-query', 'latest');
    }
}
