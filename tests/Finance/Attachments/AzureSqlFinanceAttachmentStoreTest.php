<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Attachments;

use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\Group;
use TriCab\Api\Finance\Attachments\AzureSqlFinanceAttachmentStore;
use TriCab\Api\Infrastructure\Database\PdoSqlConnection;
use TriCab\Api\Infrastructure\Observability\RequestPerformanceMetrics;
use TriCab\Api\Product\Storage\InMemoryProductBlobStore;
use TriCab\Api\Tests\Finance\LocalFinanceSqlServer;
use TriCab\Core\Finance\Shared\Attachments\FinanceAttachmentStore;

/**
 * The behaviour in {@see FinanceAttachmentStoreBehaviour}, against the rows of
 * `create-finance-attachments-schema.sql` on a real local SQL Server
 * ({@see LocalFinanceSqlServer}), with the platform's in-memory blob store for the
 * bytes; skipped without a server.
 */
#[Group('local-sql-server')]
final class AzureSqlFinanceAttachmentStoreTest extends FinanceAttachmentStoreBehaviour
{
    private const CONTAINER = 'finance-test';

    private PDO $pdo;

    private InMemoryProductBlobStore $blobs;

    protected function newStore(): FinanceAttachmentStore
    {
        $pdo = LocalFinanceSqlServer::connect();
        if ($pdo === null) {
            self::markTestSkipped('No local SQL Server (set FINANCE_TEST_SQLSERVER and FINANCE_TEST_SQLSERVER_PASSWORD).');
        }
        $this->pdo = $pdo;
        LocalFinanceSqlServer::truncate($pdo);
        InMemoryProductBlobStore::resetSharedForTests();
        $this->blobs = new InMemoryProductBlobStore();

        return new AzureSqlFinanceAttachmentStore(new PdoSqlConnection($pdo, new RequestPerformanceMetrics()), $this->blobs, self::CONTAINER);
    }

    protected function blobCount(): int
    {
        $count = 0;
        $statement = $this->pdo->query('SELECT [blob_path] FROM [finance].[attachments]');
        self::assertInstanceOf(PDOStatement::class, $statement);
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $path) {
            $count += is_string($path) && $this->blobs->hasObject(self::CONTAINER, $path) ? 1 : 0;
        }

        return $count;
    }

    public function test_a_row_that_is_half_attached_is_refused_by_the_table(): void
    {
        $this->expectException(\PDOException::class);
        $this->pdo->exec("INSERT INTO [finance].[attachments]
            ([id], [application_key], [record_type], [record_id], [blob_path], [original_name], [content_type], [byte_size], [sha256])
            VALUES ('" . str_repeat('a', 32) . "', N'AP', N'invoice', NULL, N'ap/x', N'a', N'text/plain', 1, '" . str_repeat('b', 64) . "')");
    }

    public function test_two_rows_cannot_share_a_blob_path(): void
    {
        $insert = "INSERT INTO [finance].[attachments]
            ([id], [application_key], [blob_path], [original_name], [content_type], [byte_size], [sha256])
            VALUES ('%s', N'AP', N'ap/same', N'a', N'text/plain', 1, '" . str_repeat('b', 64) . "')";
        $this->pdo->exec(sprintf($insert, str_repeat('a', 32)));

        $this->expectException(\PDOException::class);
        $this->pdo->exec(sprintf($insert, str_repeat('c', 32)));
    }

    public function test_the_bytes_are_in_the_finance_container_and_no_other(): void
    {
        $file = $this->service->upload('AP', 'a.pdf', 'application/pdf', 'AAA', 1);

        self::assertTrue($this->blobs->hasObject(self::CONTAINER, $file->blobPath));
        self::assertFalse($this->blobs->hasObject('products', $file->blobPath));
    }
}
