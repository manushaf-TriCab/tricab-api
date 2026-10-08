<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ai;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Finance\Shared\Ai\FinanceAiContract;
use TriCab\Core\Finance\Shared\Attachments\FinanceAttachmentRules;

/** The file side of `finance-ai.v1` (T5.8), written in PHP and in Python: the ceiling and the types must not drift. */
final class FinanceAiFileContractTest extends TestCase
{
    private static function python(string $file): string
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/core/python/finance_ai/' . $file);
        self::assertIsString($source);

        return $source;
    }

    public function test_the_file_ceiling_is_the_same_everywhere(): void
    {
        self::assertSame(FinanceAttachmentRules::MAX_BYTES, FinanceAiContract::MAX_FILE_BYTES);
        self::assertStringContainsString('MAX_FILE_BYTES = ' . number_format(FinanceAiContract::MAX_FILE_BYTES, 0, '', '_'), self::python('registry.py'));
        self::assertStringContainsString('MAX_FILE_BYTES = registry.MAX_FILE_BYTES', self::python('contract.py'));
    }

    public function test_the_file_types_are_the_same_list(): void
    {
        if (preg_match('/FILE_TYPES = \((.*?)\)/s', self::python('registry.py'), $match) !== 1) {
            self::fail('registry.py has no FILE_TYPES.');
        }
        preg_match_all('/"([a-z]+\/[a-z]+)"/', $match[1], $types);

        self::assertSame(FinanceAiContract::FILE_TYPES, $types[1]);
    }
}
