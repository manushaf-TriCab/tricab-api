<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Attachments;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Shared\Attachments\FinanceAttachmentService;
use TriCab\Core\Finance\Shared\Attachments\FinanceAttachmentStore;
use TriCab\Api\Finance\Attachments\InMemoryFinanceAttachmentStore;

/**
 * The finance file store has no route of its own. Each app serves its files through
 * a route of its own, behind that app's permission and a record check
 * ({@see FinanceAttachmentService::download()}), added in that app's phase.
 */
final class FinanceNoGeneralDownloadRouteTest extends TestCase
{
    /**
     * The routes an app has added for its own files in its own phase, each under the app's own permission and
     * scoped to a record. Adding one here is a decision to review; a route by file id alone is still refused.
     */
    private const APP_FILE_ROUTES = [
        '/api/v1/finance/ar/records/{id}/attachments/{aid}/file',
        // Tri-AP (T6.4, T6.5): an invoice's own document by invoice id and mailbox scope, the ticked invoices' documents
        // (every id scope-checked, the whole request a 404 if one is out of scope), and the capture dialog's upload.
        '/api/v1/finance/ap/invoices/{id}/file',
        '/api/v1/finance/ap/invoices/download',
        '/api/v1/finance/ap/uploads',
        // Tri-AP AP Mailbox (T6.6): a scanned email's attachments, listed and read live from the mailbox (never stored), each
        // by email id and mailbox scope under MAIL.READ, with the shared reader's read-grant check and an audit row.
        '/api/v1/finance/ap/emails/{id}/attachments',
        '/api/v1/finance/ap/emails/{id}/attachments/{aid}/file',
        // Tri-Task (23 Oct 2026): a task's own files by task id (the task must be visible to the caller, and the file on that very
        // task), and the unattached upload for a task not yet saved, which only its uploader can put on a task.
        '/api/v1/finance/task/tasks/{id}/files',
        '/api/v1/finance/task/tasks/{id}/files/{fid}',
        '/api/v1/finance/task/tasks/{id}/files/{fid}/file',
        '/api/v1/finance/task/task-uploads',
        // Tri-AP (round 3): an invoice's source document by invoice id, and a mail's attachment by mail id and attachment id, each
        // under its own AP2 permission and read through Ap2FileService (record type ap2_mail), never by file id alone.
        '/api/v1/finance/ap2/invoices/{id}/file',
        '/api/v1/finance/ap2/mail/{id}/attachments/{aid}/file',
    ];

    public function test_no_finance_route_serves_files_by_id(): void
    {
        $env = ['FINANCE_MODULE_ENABLED' => 'true'];
        foreach (array_values(Module::APPS) as $app) {
            $env[$app['switch']] = 'true';
        }

        $patterns = [];
        foreach (TestAppFactory::create($env)->getRouteCollector()->getRoutes() as $route) {
            if (str_starts_with($route->getPattern(), '/api/v1/finance')) {
                $patterns[] = $route->getPattern();
            }
        }

        self::assertNotSame([], $patterns, 'the finance routes were not registered');
        foreach ($patterns as $pattern) {
            if (in_array($pattern, self::APP_FILE_ROUTES, true)) {
                continue;
            }
            // "file" as a word, not inside another (Tri-Task's `/members/{userId}/profile` is a job title).
            self::assertDoesNotMatchRegularExpression('~attachment|download|(?<![a-z])files?(?![a-z])|upload|blob~i', $pattern, $pattern);
        }
    }

    public function test_the_container_holds_an_in_memory_store_and_the_service_over_it(): void
    {
        $container = TestAppFactory::create([])->getContainer();

        self::assertInstanceOf(InMemoryFinanceAttachmentStore::class, $container->get(FinanceAttachmentStore::class));
        self::assertInstanceOf(FinanceAttachmentService::class, $container->get(FinanceAttachmentService::class));
    }
}
