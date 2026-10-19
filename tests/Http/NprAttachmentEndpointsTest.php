<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\Npr\NprFixtures;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Npr\Contract\NprRequestRepository;
use TriCab\Core\Npr\Domain\RequestStatus;
use TriCab\Core\Npr\Infrastructure\InMemoryNprRequestRepository;
use TriCab\Core\Npr\Mail\NewNprOutboxEntry;
use TriCab\Core\Npr\Mail\NprMailOutbox;
use TriCab\Core\Npr\Module;

/** Attachments, the field dictionary and the notification admin endpoints, end to end. The test app signs in as user 1. */
final class NprAttachmentEndpointsTest extends TestCase
{
    private const ME = 1;
    private const MINE = '11111111-1111-4111-8111-111111111111';
    private const SUBMITTED = '22222222-2222-4222-8222-222222222222';
    private const BASE = '/api/v1/npr/me/requests/';

    private const PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n";

    /**
     * @param list<string> $permissions
     * @return App<ContainerInterface>
     */
    private function app(array $permissions): App
    {
        $app = TestAppFactory::create();
        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $users = $container->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $users);
        $users->replacePermissions(self::ME, ['account.login', ...$permissions], ['EMPLOYEE']);
        $repository = $container->get(NprRequestRepository::class);
        self::assertInstanceOf(InMemoryNprRequestRepository::class, $repository);
        $repository->withRequest(NprFixtures::record(self::MINE, self::ME, RequestStatus::Draft));
        $repository->withRequest(NprFixtures::record(self::SUBMITTED, self::ME, RequestStatus::AwaitingManager));

        return $app;
    }

    private function upload(string $id, string $name, string $bytes, string $method = 'POST'): ServerRequestInterface
    {
        $file = new UploadedFile((new StreamFactory())->createStream($bytes), $name, 'application/octet-stream', strlen($bytes), UPLOAD_ERR_OK);

        return TestAppFactory::request($method, self::BASE . $id . '/attachments')->withUploadedFiles(['file' => $file])->withParsedBody(['title' => 'Customer spec']);
    }

    /** @return array<string, mixed> */
    private function data(ResponseInterface $response): array
    {
        $payload = TestAppFactory::json($response);
        self::assertArrayHasKey('data', $payload, (string) $response->getBody());
        /** @var array<string, mixed> $data */
        $data = $payload['data'];

        return $data;
    }

    private function code(ResponseInterface $response): mixed
    {
        $error = TestAppFactory::json($response)['error'] ?? null;

        return is_array($error) ? $error['code'] ?? null : null;
    }

    public function test_upload_list_detail_download_and_soft_delete(): void
    {
        $app = $this->app([...Module::ROLE_PERMISSIONS[Module::ROLE_REQUESTER]]);

        $created = $app->handle($this->upload(self::MINE, 'spec.pdf', self::PDF));
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $file = $this->data($created);
        self::assertSame('spec.pdf', $file['filename']);
        self::assertSame('Customer spec', $file['title']);
        self::assertSame('application/pdf', $file['mimeType']);
        self::assertSame(strlen(self::PDF), $file['sizeBytes']);
        self::assertArrayNotHasKey('blobKey', $file, 'storage keys are never exposed');
        self::assertIsString($file['id']);
        $fileId = $file['id'];

        $list = $this->data($app->handle(TestAppFactory::request('GET', self::BASE . self::MINE . '/attachments')));
        self::assertIsArray($list['items']);
        self::assertCount(1, $list['items']);

        $detail = $this->data($app->handle(TestAppFactory::request('GET', self::BASE . self::MINE)));
        self::assertIsArray($detail['attachments']);
        self::assertIsArray($detail['attachments'][0]);
        self::assertSame($fileId, $detail['attachments'][0]['id'], 'request detail includes its attachments');

        $download = $app->handle(TestAppFactory::request('GET', self::BASE . self::MINE . '/attachments/' . $fileId . '/download'));
        self::assertSame(200, $download->getStatusCode());
        self::assertSame(self::PDF, (string) $download->getBody());
        self::assertSame('application/pdf', $download->getHeaderLine('Content-Type'));
        self::assertSame('attachment; filename="spec.pdf"', $download->getHeaderLine('Content-Disposition'));
        self::assertSame('nosniff', $download->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('private, no-store', $download->getHeaderLine('Cache-Control'));

        $deleted = $app->handle(TestAppFactory::request('DELETE', self::BASE . self::MINE . '/attachments/' . $fileId));
        self::assertSame(200, $deleted->getStatusCode(), (string) $deleted->getBody());
        $after = $this->data($app->handle(TestAppFactory::request('GET', self::BASE . self::MINE . '/attachments')));
        self::assertSame([], $after['items']);
        self::assertSame(404, $app->handle(TestAppFactory::request('GET', self::BASE . self::MINE . '/attachments/' . $fileId . '/download'))->getStatusCode());
        self::assertSame(404, $app->handle(TestAppFactory::request('DELETE', self::BASE . self::MINE . '/attachments/' . $fileId))->getStatusCode());
    }

    public function test_rejections_use_their_own_codes(): void
    {
        $app = $this->app([...Module::ROLE_PERMISSIONS[Module::ROLE_REQUESTER]]);

        $type = $app->handle($this->upload(self::MINE, 'a.exe', 'MZ......'));
        self::assertSame(422, $type->getStatusCode());
        self::assertSame('NPR_ATTACHMENT_TYPE_NOT_ALLOWED', $this->code($type));

        $mismatch = $app->handle($this->upload(self::MINE, 'a.pdf', "\x89PNG\r\n\x1a\n" . str_repeat('x', 30)));
        self::assertSame('NPR_ATTACHMENT_TYPE_NOT_ALLOWED', $this->code($mismatch));

        $big = $app->handle($this->upload(self::MINE, 'big.pdf', "%PDF-1.4\n" . str_repeat('x', 10 * 1024 * 1024)));
        self::assertSame(413, $big->getStatusCode());
        self::assertSame('NPR_ATTACHMENT_TOO_LARGE', $this->code($big));

        $none = $app->handle(TestAppFactory::request('POST', self::BASE . self::MINE . '/attachments')->withParsedBody([]));
        self::assertSame(422, $none->getStatusCode());
        self::assertSame('NPR_ATTACHMENT_NO_FILE', $this->code($none));

        $locked = $app->handle($this->upload(self::SUBMITTED, 'a.pdf', self::PDF));
        self::assertSame(409, $locked->getStatusCode());
        self::assertSame('NPR_NOT_EDITABLE', $this->code($locked));
    }

    public function test_someone_elses_request_is_not_found(): void
    {
        $app = $this->app([...Module::ROLE_PERMISSIONS[Module::ROLE_REQUESTER]]);
        $other = '33333333-3333-4333-8333-333333333333';
        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $repository = $container->get(NprRequestRepository::class);
        self::assertInstanceOf(InMemoryNprRequestRepository::class, $repository);
        $repository->withRequest(NprFixtures::record($other, 99, RequestStatus::Draft));

        self::assertSame(404, $app->handle(TestAppFactory::request('GET', self::BASE . $other . '/attachments'))->getStatusCode());
        self::assertSame(404, $app->handle($this->upload($other, 'a.pdf', self::PDF))->getStatusCode());
    }

    public function test_the_field_dictionary_is_served_to_whoever_can_read_requests(): void
    {
        $app = $this->app([Module::PERMISSION_SELF_READ]);
        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/npr/field-dictionary'));
        $data = $this->data($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/resources/npr/field-dictionary.json'), true),
            $data,
            'the file as it is',
        );
        self::assertArrayHasKey('sections', $data);

        $denied = $this->app([Module::PERMISSION_TSD_READ])->handle(TestAppFactory::request('GET', '/api/v1/npr/field-dictionary'));
        self::assertSame(403, $denied->getStatusCode());

        $tsd = $this->app([Module::PERMISSION_TSD_READ])->handle(TestAppFactory::request('GET', '/api/v1/npr/tsd/field-dictionary'));
        self::assertSame(200, $tsd->getStatusCode());
        self::assertSame($data, $this->data($tsd), 'TSD reads the same file through its own door');
    }

    public function test_the_admin_lists_deliveries_and_retries_a_suppressed_one(): void
    {
        $app = $this->app([Module::PERMISSION_ADMIN]);
        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $outbox = $container->get(NprMailOutbox::class);
        self::assertInstanceOf(NprMailOutbox::class, $outbox);
        $id = $outbox->enqueue(new NewNprOutboxEntry('manager_endorsed', self::MINE, ['a@example.test'], [], [['role' => 'tsd_queue', 'type' => 'to']], ['requestNumber' => 'NPR-2026-0001'], 'suppressed'), 1, 1);

        $list = $this->data($app->handle(TestAppFactory::request('GET', '/api/v1/npr/admin/notifications')));
        self::assertSame('off', $list['mode']);
        self::assertIsArray($list['items']);
        self::assertCount(1, $list['items']);
        $row = $list['items'][0];
        self::assertIsArray($row);
        self::assertIsArray($row['deliveries']);
        self::assertIsArray($row['deliveries'][1]);
        self::assertSame('suppressed', $row['status']);
        self::assertTrue($row['hasMissingRecipients']);
        self::assertSame('(missing: tsd_queue)', $row['deliveries'][1]['recipient']);
        self::assertSame('failed', $row['deliveries'][1]['status']);

        $filtered = $this->data($app->handle(TestAppFactory::request('GET', '/api/v1/npr/admin/notifications?status=sent')));
        self::assertSame([], $filtered['items']);

        $retried = $app->handle(TestAppFactory::request('POST', '/api/v1/npr/admin/notifications/' . $id . '/retry'));
        self::assertSame(200, $retried->getStatusCode(), (string) $retried->getBody());
        self::assertSame('pending', $this->data($retried)['status'], 'mail is off, so it is reopened but not sent');
        self::assertSame(404, $app->handle(TestAppFactory::request('POST', '/api/v1/npr/admin/notifications/' . $id . '/retry'))->getStatusCode(), 'a pending row is not retryable');
        self::assertSame(404, $app->handle(TestAppFactory::request('POST', '/api/v1/npr/admin/notifications/999/retry'))->getStatusCode());
    }
}
