<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\DocumentGeneration\Application;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TriCab\Core\DocumentGeneration\Application\Exception\InvalidOcrStream;
use TriCab\Core\DocumentGeneration\Application\Exception\UnsupportedDocumentTemplate;
use TriCab\Core\DocumentGeneration\Application\Handler\GenerateOcrPdfHandler;
use TriCab\Core\DocumentGeneration\Legacy\LegacyStreamParser;
use TriCab\Core\Tests\DocumentGeneration\Doubles\FakePdfRenderer;

final class GenerateOcrPdfHandlerTest extends TestCase
{
    #[DataProvider('supportedDocumentProvider')]
    public function test_generates_supported_document(
        string $fixture,
        string $templateName,
        string $filename,
    ): void {
        $renderer = new FakePdfRenderer();
        $handler = new GenerateOcrPdfHandler(new LegacyStreamParser(), $renderer);

        $result = $handler->handle($this->fixture($fixture));

        self::assertSame('%PDF-1.4 fake document', $result->contents);
        self::assertSame($templateName, $result->templateName);
        self::assertSame($filename, $result->filename);
        self::assertSame($templateName, $renderer->lastStream?->templateName());
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function supportedDocumentProvider(): iterable
    {
        yield 'order confirmation' => ['tcord.ocr.txt', 'tcord.ocr', 'order-confirmation.pdf'];
        yield 'pick list' => ['tcpick.ocr.txt', 'tcpick.ocr', 'pick-list.pdf'];
        yield 'quotation' => ['tcquo.ocr.txt', 'tcqu.ocr', 'quotation.pdf'];
    }

    public function test_rejects_empty_stream(): void
    {
        $handler = new GenerateOcrPdfHandler(new LegacyStreamParser(), new FakePdfRenderer());

        $this->expectException(InvalidOcrStream::class);
        $this->expectExceptionMessage('The OCR stream is empty.');

        $handler->handle('');
    }

    public function test_rejects_unsupported_template(): void
    {
        $handler = new GenerateOcrPdfHandler(new LegacyStreamParser(), new FakePdfRenderer());

        $this->expectException(UnsupportedDocumentTemplate::class);

        $handler->handle('.RET.C:\\legacy\\unknown.ocr');
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
