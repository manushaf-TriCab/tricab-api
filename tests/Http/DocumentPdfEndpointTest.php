<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Tests\TestAppFactory;

final class DocumentPdfEndpointTest extends TestCase
{
    #[DataProvider('documentProvider')]
    public function test_generates_pdf_from_raw_ocr_stream(
        string $fixture,
        string $templateName,
        string $filename,
        string $warningCount,
    ): void {
        $app = TestAppFactory::create();
        $request = $this->requestWithBody($this->fixture($fixture));

        $response = $app->handle($request);
        $response->getBody()->rewind();
        $pdf = $response->getBody()->getContents();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/pdf', $response->getHeaderLine('Content-Type'));
        self::assertSame($templateName, $response->getHeaderLine('X-OCR-Template'));
        self::assertSame($warningCount, $response->getHeaderLine('X-OCR-Warning-Count'));
        self::assertSame(
            sprintf('attachment; filename="%s"', $filename),
            $response->getHeaderLine('Content-Disposition'),
        );
        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertGreaterThan(10_000, strlen($pdf));
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function documentProvider(): iterable
    {
        yield 'order confirmation' => ['tcord.ocr.txt', 'tcord.ocr', 'order-confirmation.pdf', '1'];
        yield 'pick list' => ['tcpick.ocr.txt', 'tcpick.ocr', 'pick-list.pdf', '0'];
        yield 'quotation' => ['tcquo.ocr.txt', 'tcqu.ocr', 'quotation.pdf', '0'];
    }

    public function test_requires_authenticated_identity(): void
    {
        $app = TestAppFactory::create([
            'DEV_AUTH_ENABLED' => 'false',
        ]);

        $response = $app->handle($this->requestWithBody($this->fixture('tcquo.ocr.txt')));
        $payload = TestAppFactory::json($response);

        self::assertSame(401, $response->getStatusCode());
        self::assertFalse($payload['success']);
        self::assertIsArray($payload['error']);
        /** @var array<string, mixed> $error */
        $error = $payload['error'];
        self::assertSame('UNAUTHENTICATED', $error['code']);
    }

    public function test_rejects_invalid_ocr_stream(): void
    {
        $app = TestAppFactory::create();

        $response = $app->handle($this->requestWithBody('not an OCR stream'));
        $payload = TestAppFactory::json($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertFalse($payload['success']);
        self::assertIsArray($payload['error']);
        /** @var array<string, mixed> $error */
        $error = $payload['error'];
        self::assertSame('INVALID_OCR_STREAM', $error['code']);
    }

    public function test_rejects_empty_request_body(): void
    {
        $app = TestAppFactory::create();

        $response = $app->handle($this->requestWithBody(''));
        $payload = TestAppFactory::json($response);

        self::assertSame(400, $response->getStatusCode());
        self::assertIsArray($payload['error']);
        /** @var array<string, mixed> $error */
        $error = $payload['error'];
        self::assertSame('EMPTY_OCR_STREAM', $error['code']);
    }

    public function test_rejects_unsupported_media_type(): void
    {
        $app = TestAppFactory::create();
        $request = $this->requestWithBody($this->fixture('tcquo.ocr.txt'))
            ->withHeader('Content-Type', 'application/json');

        $response = $app->handle($request);
        $payload = TestAppFactory::json($response);

        self::assertSame(415, $response->getStatusCode());
        self::assertIsArray($payload['error']);
        /** @var array<string, mixed> $error */
        $error = $payload['error'];
        self::assertSame('UNSUPPORTED_OCR_MEDIA_TYPE', $error['code']);
    }

    public function test_rejects_unsupported_ocr_template(): void
    {
        $app = TestAppFactory::create();

        $response = $app->handle($this->requestWithBody('.RET.C:\\legacy\\unknown.ocr'));
        $payload = TestAppFactory::json($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertIsArray($payload['error']);
        /** @var array<string, mixed> $error */
        $error = $payload['error'];
        self::assertSame('INVALID_OCR_STREAM', $error['code']);
        self::assertSame('The OCR template unknown.ocr is not supported.', $error['message']);
    }

    private function requestWithBody(string $contents): ServerRequestInterface
    {
        $request = TestAppFactory::request('POST', '/api/v1/documents/pdf')
            ->withHeader('Content-Type', 'text/plain; charset=utf-8')
            ->withHeader('Accept', 'application/pdf');
        $request->getBody()->write($contents);

        return $request;
    }

    private function fixture(string $filename): string
    {
        $contents = file_get_contents(dirname(__DIR__) . '/Fixtures/DocumentGeneration/' . $filename);
        if ($contents === false) {
            self::fail(sprintf('Unable to read fixture: %s', $filename));
        }

        return $contents;
    }
}
