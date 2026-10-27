<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;
use TriCab\Api\Tests\TestAppFactory;

final class CustomerContactReportFileEndpointsTest extends TestCase
{
    public function test_evidence_upload_is_intake_only_and_forces_the_evidence_kind(): void
    {
        $app = TestAppFactory::create(['CUSTOMER_CONTACT_MODULE_ENABLED' => 'true']);
        $created = $this->record($this->data($app->handle($this->json('POST', '/api/v1/customer-contacts', [
            'reportDate' => '2026-09-30',
            'location' => 'M',
            'customerCode' => 'CUST1',
            'productCode' => 'CAB-1',
            'orderNo' => '639952',
            'creditRequired' => 'No',
            'description' => 'Cable failed',
        ])), 201));
        $publicId = $this->text($created, 'publicId');

        $rejected = $app->handle($this->upload('/api/v1/customer-contacts/' . $publicId . '/attachments', "\xFF\xD8\xFF", 'photo.jpg', 'image/jpeg', [
            'kind' => 'credit_note',
        ]));
        self::assertSame(422, $rejected->getStatusCode());
        self::assertSame('CLIENT_FIELD_REJECTED', $this->errorCode($rejected));

        $stored = $this->record($this->data($app->handle($this->upload(
            '/api/v1/customer-contacts/' . $publicId . '/attachments',
            "\xFF\xD8\xFF",
            'photo.jpg',
            'image/jpeg',
        )), 201));
        self::assertSame('submitted', $stored['status'] ?? null);
        $items = $this->recordList($stored['attachments'] ?? null);
        self::assertSame('evidence', $items[0]['kind'] ?? null);
        self::assertSame('photo.jpg', $items[0]['fileName'] ?? null);

        $listed = $this->record($this->data($app->handle(TestAppFactory::request(
            'GET',
            '/api/v1/customer-contacts/' . $publicId . '/attachments',
        ))));
        self::assertSame('evidence', $this->recordList($listed['items'] ?? null)[0]['kind'] ?? null);

        $note = $app->handle($this->upload('/api/v1/customer-contacts/' . $publicId . '/credit-note', '%PDF-1.4', 'note.pdf', 'application/pdf', [
            'rowVersion' => $this->text($created, 'rowVersion'),
        ]));
        self::assertSame(422, $note->getStatusCode());
        self::assertSame('CUSTOMER_CONTACT_CREDIT_NOTE_NOT_ALLOWED', $this->errorCode($note));

        $signature = $app->handle($this->json('POST', '/api/v1/customer-contacts/' . $publicId . '/icar-signature', [
            'signature' => 'data:image/png;base64,' . base64_encode("\x89PNG\r\n\x1a\n"),
            'icarOperatorName' => 'Alex',
            'rowVersion' => $this->text($stored, 'rowVersion'),
        ]));
        self::assertSame(403, $signature->getStatusCode());
        self::assertSame('CUSTOMER_CONTACT_ICAR_SIGNATURE_NOT_ALLOWED', $this->errorCode($signature));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function json(string $method, string $path, array $body): ServerRequestInterface
    {
        $request = TestAppFactory::request($method, $path)->withHeader('Content-Type', 'application/json');
        $request->getBody()->write(json_encode($body, JSON_THROW_ON_ERROR));

        return $request;
    }

    /**
     * @param array<string, string> $fields
     */
    private function upload(string $path, string $bytes, string $name, string $mediaType, array $fields = []): ServerRequestInterface
    {
        $upload = new UploadedFile((new StreamFactory())->createStream($bytes), $name, $mediaType, strlen($bytes), UPLOAD_ERR_OK);
        $request = TestAppFactory::request('POST', $path)->withUploadedFiles(['file' => $upload]);
        if ($fields !== []) {
            $request = $request->withParsedBody($fields);
        }

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function data(ResponseInterface $response, int $status = 200): array
    {
        self::assertSame($status, $response->getStatusCode(), (string) $response->getBody());

        return $this->record(TestAppFactory::json($response)['data'] ?? null);
    }

    private function errorCode(ResponseInterface $response): string
    {
        $error = $this->record(TestAppFactory::json($response)['error'] ?? null);
        $code = $error['code'] ?? null;
        self::assertIsString($code);

        return $code;
    }

    /**
     * @param array<string, mixed> $record
     */
    private function text(array $record, string $key): string
    {
        $value = $record[$key] ?? null;
        if (!is_string($value)) {
            self::fail($key . ' was not a string.');
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function record(mixed $value): array
    {
        if (!is_array($value)) {
            self::fail('Expected a JSON object.');
        }
        $record = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                self::fail('Expected string keys.');
            }
            $record[$key] = $item;
        }

        return $record;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recordList(mixed $value): array
    {
        if (!is_array($value)) {
            self::fail('Expected a list.');
        }
        $items = [];
        foreach ($value as $item) {
            $items[] = $this->record($item);
        }

        return $items;
    }
}
