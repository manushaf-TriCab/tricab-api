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

    public function test_rejects_unversioned_or_path_traversal_names(): void
    {
        $repository = new MarkdownPromptRepository(dirname(__DIR__, 2) . '/PROMPTS');

        $this->expectException(RuntimeException::class);
        $repository->get('../product-assistant-query', 'latest');
    }
}
