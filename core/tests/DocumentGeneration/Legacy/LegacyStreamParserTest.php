<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\DocumentGeneration\Legacy;

use PHPUnit\Framework\TestCase;
use TriCab\Core\DocumentGeneration\Application\Exception\InvalidOcrStream;
use TriCab\Core\DocumentGeneration\Legacy\LegacyStreamParser;

final class LegacyStreamParserTest extends TestCase
{
    private LegacyStreamParser $parser;

    protected function setUp(): void
    {
        $this->parser = new LegacyStreamParser();
    }

    public function test_parses_quotation_records(): void
    {
        $quote = $this->parser->parse($this->fixture('tcquo.ocr.txt'));

        self::assertSame('tcqu.ocr', $quote->templateName());
        self::assertCount(5, $quote->all('S009'));
        self::assertCount(5, $quote->all('V4203'));
        self::assertSame(7, $quote->first('S026')?->declaredFieldCount);
    }

    public function test_parses_pick_manufacturer_data(): void
    {
        $pick = $this->parser->parse($this->fixture('tcpick.ocr.txt'));

        self::assertSame('tcpick.ocr', $pick->templateName());
        self::assertSame(26, $pick->first('S006')?->declaredFieldCount);
        self::assertNotSame('', $pick->first('S009')?->field(14));
    }

    public function test_strict_parsing_rejects_truncated_order_record(): void
    {
        $this->expectException(InvalidOcrStream::class);

        $this->parser->parse($this->fixture('tcord.ocr.txt'));
    }

    public function test_diagnostic_parsing_pads_truncated_order_record(): void
    {
        $order = $this->parser->parse(
            $this->fixture('tcord.ocr.txt'),
            allowTruncatedFinalRecord: true,
        );

        self::assertCount(1, $order->warnings);
        self::assertCount(3, $order->all('S009'));
        self::assertSame(18, $order->all('S009')[2]->declaredFieldCount);
        self::assertCount(18, $order->all('S009')[2]->fields);
    }

    public function test_rejects_content_without_template_selector(): void
    {
        $this->expectException(InvalidOcrStream::class);
        $this->expectExceptionMessage('Expected a .RET. template selector at line 1.');

        $this->parser->parse('not an OCR stream');
    }

    private function fixture(string $filename): string
    {
        $contents = file_get_contents(dirname(__DIR__) . '/Fixtures/' . $filename);
        if ($contents === false) {
            self::fail(sprintf('Unable to read fixture: %s', $filename));
        }

        return $contents;
    }
}
