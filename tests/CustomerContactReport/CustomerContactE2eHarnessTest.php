<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\CustomerContactReport;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use TriCab\Api\CustomerContactReport\Identity\CustomerContactE2eDirectory;
use TriCab\Api\CustomerContactReport\Identity\DevelopmentCustomerContactReportReportingLine;
use TriCab\Api\CustomerContactReport\Persistence\CustomerContactE2ePaths;
use TriCab\Api\CustomerContactReport\Persistence\DevelopmentFileCustomerContactReportRepository;
use TriCab\Api\CustomerContactReport\Persistence\InMemoryCustomerContactReportRepository;
use TriCab\Api\CustomerContactReport\Storage\DevelopmentFileCustomerContactReportFileStore;
use TriCab\Api\Identity\Persistence\DevelopmentUserManagementCatalog;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportVisibility;
use TriCab\Core\CustomerContactReport\Application\DTO\NewCustomerContactReport;
use TriCab\Core\CustomerContactReport\Contract\CustomerContactReportFileStore;
use TriCab\Core\CustomerContactReport\Contract\CustomerContactReportNotifier;
use TriCab\Core\CustomerContactReport\Contract\CustomerContactReportReportingLine;
use TriCab\Core\CustomerContactReport\Contract\CustomerContactReportRepository;
use TriCab\Core\CustomerContactReport\Domain\ValueObject\CustomerContactReportPublicId;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;

final class CustomerContactE2eHarnessTest extends TestCase
{
    public function test_file_repository_survives_a_new_instance(): void
    {
        $directory = sys_get_temp_dir() . '/tricab-cc-e2e-test-' . bin2hex(random_bytes(4));
        mkdir($directory, 0700, true);
        $path = $directory . '/repository.ser';

        try {
            $first = new DevelopmentFileCustomerContactReportRepository($path);
            $created = $first->insert(new NewCustomerContactReport(
                publicId: '550e8400-e29b-41d4-a716-446655440099',
                createdByUserId: 2,
                createdByEmail: CustomerContactE2eDirectory::CREATOR_LOGIN,
                createdByName: 'E2E Creator',
                createdAtUtc: new DateTimeImmutable('2026-09-30 00:00:00', new DateTimeZone('UTC')),
                reportDate: new DateTimeImmutable('2026-09-30'),
                location: 'M',
                customerCode: CustomerContactE2eDirectory::CUSTOMER_CODE,
                customerContact: null,
                tricabRefTrace: null,
                traceNum: null,
                conNoteNo: null,
                tricabContact: null,
                productCode: 'ABC-1',
                description: 'Persisted across instances',
            ));

            $second = new DevelopmentFileCustomerContactReportRepository($path);
            $loaded = $second->findVisible(
                CustomerContactReportPublicId::fromString($created->publicId),
                new CustomerContactReportVisibility(true, 1, 'reader@tricab.test'),
            );

            self::assertNotNull($loaded);
            self::assertSame('Persisted across instances', $loaded->description);
        } finally {
            if (is_dir($directory)) {
                $files = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::CHILD_FIRST,
                );
                foreach ($files as $file) {
                    $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
                }
                rmdir($directory);
            }
        }
    }

    public function test_file_store_keeps_bytes_on_disk(): void
    {
        $directory = sys_get_temp_dir() . '/tricab-cc-e2e-files-' . bin2hex(random_bytes(4));
        $store = new DevelopmentFileCustomerContactReportFileStore($directory);
        $store->put('cc/2026/09/note.bin', 'application/pdf', 'pdf-bytes');

        self::assertSame('pdf-bytes', file_get_contents($directory . '/cc/2026/09/note.bin'));

        $store->delete('cc/2026/09/note.bin');
        self::assertFileDoesNotExist($directory . '/cc/2026/09/note.bin');
        @rmdir($directory . '/cc/2026/09');
        @rmdir($directory . '/cc/2026');
        @rmdir($directory . '/cc');
        @rmdir($directory);
    }

    public function test_catalog_employee_still_lacks_privileged_customer_contact_permissions(): void
    {
        $codes = DevelopmentUserManagementCatalog::managementPermissionCodes();
        self::assertNotContains('CUSTOMER_CONTACT.ASSIGN', $codes);
        self::assertNotContains('CUSTOMER_CONTACT.CLOSE', $codes);
        self::assertNotContains('CUSTOMER_CONTACT.REOPEN', $codes);
        self::assertNotContains('CUSTOMER_CONTACT.REMEDY', $codes);
        self::assertNotContains('CUSTOMER_CONTACT.APPROVE', $codes);
        self::assertNotContains('CUSTOMER_CONTACT.READ_ALL', $codes);
    }

    public function test_e2e_app_uses_file_repository_and_keeps_admin_grants_unchanged(): void
    {
        $directory = sys_get_temp_dir() . '/tricab-cc-e2e-app-' . bin2hex(random_bytes(4));
        mkdir($directory, 0700, true);
        $_ENV['CUSTOMER_CONTACT_E2E_DIRECTORY'] = $directory;
        putenv('CUSTOMER_CONTACT_E2E_DIRECTORY=' . $directory);

        try {
            $app = TestAppFactory::create([
                'CUSTOMER_CONTACT_MODULE_ENABLED' => 'true',
                'CUSTOMER_CONTACT_E2E_MODE' => 'true',
                'CUSTOMER_CONTACT_EMAIL_ENABLED' => 'false',
                'BLOB_AUTH_MODE' => 'memory',
            ]);
            $container = $app->getContainer();
            self::assertNotNull($container);

            $reports = $container->get(CustomerContactReportRepository::class);
            $files = $container->get(CustomerContactReportFileStore::class);
            $mail = $container->get(CustomerContactReportNotifier::class);
            $line = $container->get(CustomerContactReportReportingLine::class);
            $users = $container->get(UserRepository::class);

            self::assertInstanceOf(DevelopmentFileCustomerContactReportRepository::class, $reports);
            self::assertInstanceOf(DevelopmentFileCustomerContactReportFileStore::class, $files);
            self::assertInstanceOf(DevelopmentCustomerContactReportReportingLine::class, $line);
            self::assertStringContainsString('InMemory', $mail::class);
            self::assertNotInstanceOf(InMemoryCustomerContactReportRepository::class, $reports);

            self::assertInstanceOf(InMemoryApiUserRepository::class, $users);
            $admin = $users->findByEmail(EmailAddress::fromString(DevelopmentUserManagementCatalog::USER_EMAIL));
            self::assertNotNull($admin);
            $adminCodes = array_map(static fn ($permission) => $permission->toString(), $admin->permissions());
            self::assertNotContains('CUSTOMER_CONTACT.ASSIGN', $adminCodes);
            self::assertContains('CUSTOMER_CONTACT.CREATE', $adminCodes);

            $creator = $users->findByEmail(EmailAddress::fromString(CustomerContactE2eDirectory::CREATOR_LOGIN));
            self::assertNotNull($creator);
            $creatorCodes = array_map(static fn ($permission) => $permission->toString(), $creator->permissions());
            self::assertNotContains('CUSTOMER_CONTACT.ASSIGN', $creatorCodes);
            self::assertContains('CUSTOMER_CONTACT.CREATE', $creatorCodes);

            $manager = $line->managerOf(CustomerContactE2eDirectory::CREATOR_LOGIN);
            self::assertNotNull($manager);
            self::assertSame(CustomerContactE2eDirectory::MANAGER_LOGIN, $manager->login);
            $upper = $line->managerOf($manager->login);
            self::assertNotNull($upper);
            self::assertSame(CustomerContactE2eDirectory::UPPER_LOGIN, $upper->login);
        } finally {
            CustomerContactE2ePaths::reset();
            unset($_ENV['CUSTOMER_CONTACT_E2E_DIRECTORY']);
            putenv('CUSTOMER_CONTACT_E2E_DIRECTORY');
        }
    }

    public function test_memory_mode_without_e2e_keeps_the_in_memory_repository(): void
    {
        $app = TestAppFactory::create([
            'CUSTOMER_CONTACT_MODULE_ENABLED' => 'true',
            'CUSTOMER_CONTACT_EMAIL_ENABLED' => 'false',
        ]);
        $container = $app->getContainer();
        self::assertNotNull($container);
        self::assertInstanceOf(
            InMemoryCustomerContactReportRepository::class,
            $container->get(CustomerContactReportRepository::class),
        );
    }
}
