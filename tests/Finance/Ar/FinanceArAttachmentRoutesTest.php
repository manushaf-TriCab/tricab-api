<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ar;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use TriCab\Api\Finance\Ar\InMemoryArStore;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Finance\Ar\Service\ArAttachmentService;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Shared\Attachments\FinanceUnattachedAttachmentsJob;
use TriCab\Core\Finance\Shared\Jobs\FinanceJobRegistry;
use TriCab\Core\Identity\Contract\UserRepository;

/** The kept-file route (T5.5) through the real app on the in-memory stores: headers, permission and refusals. */
final class FinanceArAttachmentRoutesTest extends TestCase
{
    private const BASE = '/api/v1/finance/ar';

    /** @var App<ContainerInterface> */
    private App $app;

    private string $attachmentId;

    protected function setUp(): void
    {
        $this->app = TestAppFactory::create(['FINANCE_MODULE_ENABLED' => 'true', 'FINANCE_AR_ENABLED' => 'true']);
        $store = $this->app->getContainer()->get(InMemoryArStore::class);
        self::assertInstanceOf(InMemoryArStore::class, $store);
        $store->insert(ArFixtures::record('r1'));
        $store->insert(ArFixtures::record('r2'));
        $service = $this->app->getContainer()->get(ArAttachmentService::class);
        self::assertInstanceOf(ArAttachmentService::class, $service);
        $this->attachmentId = $service->keep('r1', 'Payment advice.pdf', 'application/pdf', '%PDF-1.4 invented bytes');
    }

    /**
     * @param list<string> $permissions
     */
    private function get(string $path, array $permissions): ResponseInterface
    {
        $users = $this->app->getContainer()->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $users);
        $users->replacePermissions(1, ['account.login', Module::PERMISSION_ACCESS, ...$permissions], ['EMPLOYEE']);

        return $this->app->handle(TestAppFactory::request('GET', self::BASE . $path));
    }

    public function test_the_file_is_served_as_a_download_with_the_locked_down_headers(): void
    {
        $response = $this->get('/records/r1/attachments/' . $this->attachmentId . '/file', [Module::PERMISSION_AR_ATTACHMENTS_READ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/pdf', $response->getHeaderLine('Content-Type'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame("default-src 'none'; sandbox", $response->getHeaderLine('Content-Security-Policy'));
        self::assertStringStartsWith('attachment; filename="Payment advice.pdf"', $response->getHeaderLine('Content-Disposition'));
        self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('%PDF-1.4 invented bytes', (string) $response->getBody());
    }

    public function test_inline_one_renders_in_the_browser_and_anything_else_downloads(): void
    {
        $path = '/records/r1/attachments/' . $this->attachmentId . '/file';

        $inline = $this->get($path . '?inline=1', [Module::PERMISSION_AR_ATTACHMENTS_READ]);
        self::assertStringStartsWith('inline;', $inline->getHeaderLine('Content-Disposition'));
        self::assertSame('nosniff', $inline->getHeaderLine('X-Content-Type-Options'));
        self::assertSame("default-src 'none'; sandbox", $inline->getHeaderLine('Content-Security-Policy'));

        $other = $this->get($path . '?inline=true', [Module::PERMISSION_AR_ATTACHMENTS_READ]);
        self::assertStringStartsWith('attachment;', $other->getHeaderLine('Content-Disposition'));
    }

    public function test_reading_records_alone_does_not_open_the_file(): void
    {
        $response = $this->get('/records/r1/attachments/' . $this->attachmentId . '/file', [Module::PERMISSION_AR_RECORDS_READ, Module::PERMISSION_AR_RECORDS_WRITE]);

        self::assertSame(403, $response->getStatusCode());
    }

    public function test_the_attachment_of_another_record_and_an_unknown_one_are_404_with_the_ar_code(): void
    {
        $other = $this->get('/records/r2/attachments/' . $this->attachmentId . '/file', [Module::PERMISSION_AR_ATTACHMENTS_READ]);
        self::assertSame(404, $other->getStatusCode());
        $json = TestAppFactory::json($other);
        $error = $json['error'];
        self::assertIsArray($error);
        self::assertSame('FINANCE_AR_NOT_FOUND', $error['code']);
        self::assertSame('Attachment not found', $error['message']);

        $unknown = $this->get('/records/r1/attachments/nope/file', [Module::PERMISSION_AR_ATTACHMENTS_READ]);
        self::assertSame(404, $unknown->getStatusCode());
    }

    public function test_the_unattached_upload_sweep_is_registered_when_the_app_is_on_and_not_when_off(): void
    {
        $registry = $this->app->getContainer()->get(FinanceJobRegistry::class);
        self::assertInstanceOf(FinanceJobRegistry::class, $registry);
        self::assertContains(FinanceUnattachedAttachmentsJob::NAME, array_map(static fn ($job): string => $job->name(), $registry->all()));

        $off = TestAppFactory::create(['FINANCE_MODULE_ENABLED' => 'true', 'FINANCE_AR_ENABLED' => 'false']);
        $registry = $off->getContainer()->get(FinanceJobRegistry::class);
        self::assertInstanceOf(FinanceJobRegistry::class, $registry);
        self::assertNotContains(FinanceUnattachedAttachmentsJob::NAME, array_map(static fn ($job): string => $job->name(), $registry->all()));
    }

    public function test_the_original_message_route_answers_404_for_a_record_with_no_message_and_refuses_without_a_grant(): void
    {
        $store = $this->app->getContainer()->get(InMemoryArStore::class);
        self::assertInstanceOf(InMemoryArStore::class, $store);
        $store->insert(ArFixtures::record('nomsg', sourceMessageId: null));
        $store->insert(ArFixtures::record('moved', sourceMessageId: 'imm-1', messageUnreachable: true));
        $store->insert(ArFixtures::record('live', sourceMessageId: 'imm-2'));

        $none = $this->get('/records/nomsg/body', [Module::PERMISSION_AR_RECORDS_READ]);
        self::assertSame(404, $none->getStatusCode());
        self::assertSame('This record did not come from the mailbox', self::errorOf($none)['message']);

        $moved = $this->get('/records/moved/body', [Module::PERMISSION_AR_RECORDS_READ]);
        self::assertSame(404, $moved->getStatusCode());
        self::assertSame('The original message is no longer reachable', self::errorOf($moved)['message']);

        $missing = $this->get('/records/ghost/body', [Module::PERMISSION_AR_RECORDS_READ]);
        self::assertSame('Record not found', self::errorOf($missing)['message']);

        // The registry holds no mailbox in this app, so the finance read rules refuse it: a typed 403, nothing read.
        $refused = $this->get('/records/live/body', [Module::PERMISSION_AR_RECORDS_READ]);
        self::assertSame(403, $refused->getStatusCode());
        self::assertSame('FINANCE_AR_FORBIDDEN', self::errorOf($refused)['code']);

        $noPermission = $this->get('/records/live/body', [Module::PERMISSION_AR_ATTACHMENTS_READ]);
        self::assertSame(403, $noPermission->getStatusCode());
        self::assertNotSame('FINANCE_AR_FORBIDDEN', self::errorOf($noPermission)['code'] ?? null, 'the platform refused it before the use case ran');
    }

    /**
     * @return array<string, mixed>
     */
    private static function errorOf(ResponseInterface $response): array
    {
        $error = TestAppFactory::json($response)['error'] ?? [];
        self::assertIsArray($error);
        $out = [];
        foreach ($error as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }
}
