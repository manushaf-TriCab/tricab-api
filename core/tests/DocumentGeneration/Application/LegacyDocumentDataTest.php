<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\DocumentGeneration\Application;

use PHPUnit\Framework\TestCase;
use TriCab\Core\DocumentGeneration\Application\DTO\LegacyDocumentData;
use TriCab\Core\DocumentGeneration\Legacy\LegacyStreamParser;

final class LegacyDocumentDataTest extends TestCase
{
    public function test_groups_item_comments_and_exposes_addresses(): void
    {
        $contents = file_get_contents(dirname(__DIR__) . '/Fixtures/tcquo.ocr.txt');
        if ($contents === false) {
            self::fail('Unable to read quotation fixture.');
        }

        $document = new LegacyDocumentData((new LegacyStreamParser())->parse($contents));
        $items = $document->items();

        self::assertCount(5, $items);
        self::assertCount(1, $items[0]['comments']);
        self::assertSame('Beacon Electrical Supplies', $document->addressLines('S008')[0]);
    }
}
