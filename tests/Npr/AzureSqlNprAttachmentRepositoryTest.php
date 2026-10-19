<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Npr;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Npr\Persistence\AzureSqlNprAttachmentRepository;
use TriCab\Api\Tests\Doubles\FakeSqlConnection;
use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Core\Npr\Domain\NewNprAttachment;
use TriCab\Core\Npr\Domain\NprPerson;

final class AzureSqlNprAttachmentRepositoryTest extends TestCase
{
    private const REQUEST = '5e8f0a3c-1b2d-4c3e-8f4a-0123456789ab';
    private const FILE = 'aaaaaaaa-1b2d-4c3e-8f4a-0123456789ab';

    private FakeSqlConnection $connection;
    private AzureSqlNprAttachmentRepository $repository;

    protected function setUp(): void
    {
        $this->connection = new FakeSqlConnection();
        $this->repository = new AzureSqlNprAttachmentRepository($this->connection, FixedClock::utc('2026-10-19 03:00:00'));
    }

    /** @return array<string, mixed> */
    private function row(): array
    {
        return [
            'PublicId' => strtoupper(self::FILE), 'RequestPublicId' => strtoupper(self::REQUEST), 'OriginalFilename' => 'spec.pdf',
            'BlobKey' => self::REQUEST . '/abc.pdf', 'FileSizeBytes' => 1234, 'MimeType' => 'application/pdf', 'DocumentType' => 'datasheet',
            'Title' => 'Spec', 'UploadedByUserId' => 10, 'UploadedByEmail' => 'amandaw@tricab.com', 'UploadedByName' => 'Amanda',
            'UploadedAt' => '2026-10-19 03:00:00.000',
        ];
    }

    public function test_an_add_writes_the_file_and_one_activity_row_in_one_transaction(): void
    {
        $this->connection->queueFetch([['RequestId' => 55, 'CurrentRevision' => 2, 'Status' => 'draft']]);
        $this->connection->queueFetch([['PublicId' => strtoupper(self::FILE)]]);
        $this->connection->queueFetch([$this->row()]);

        $saved = $this->repository->add(self::REQUEST, new NewNprAttachment('spec.pdf', self::REQUEST . '/abc.pdf', 1234, 'application/pdf', 'datasheet', 'Spec', new NprPerson(10, 'amandaw@tricab.com', 'Amanda')), 'requester');

        self::assertNotNull($saved);
        self::assertSame(self::FILE, $saved->publicId);
        self::assertSame(self::REQUEST, $saved->requestPublicId);
        self::assertTrue($this->connection->committed);
        $sql = array_column($this->connection->calls, 'sql');
        self::assertSame(1, count(array_filter($sql, static fn (string $s): bool => str_contains($s, 'INSERT INTO [npr].[Attachments]'))));
        $activity = array_values(array_filter($this->connection->calls, static fn (array $c): bool => str_contains($c['sql'], 'INSERT INTO [npr].[Activity]')));
        self::assertCount(1, $activity);
        self::assertSame('attachment_added', $activity[0]['params']['action']);
        self::assertSame(2, $activity[0]['params']['revision']);
        self::assertSame('requester', $activity[0]['params']['role']);
        self::assertSame(55, $activity[0]['params']['requestId']);
        self::assertSame('draft', $activity[0]['params']['before']);
        self::assertSame('draft', $activity[0]['params']['after']);
    }

    public function test_a_soft_delete_sets_the_flag_and_writes_one_activity_row(): void
    {
        $this->connection->queueFetch([['RequestId' => 55, 'CurrentRevision' => 1, 'Status' => 'draft']]);
        $this->connection->queueFetch([$this->row()]);

        $removed = $this->repository->softDelete(self::REQUEST, self::FILE, new NprPerson(10, 'amandaw@tricab.com', 'Amanda'), 'requester');

        self::assertSame('spec.pdf', $removed?->filename);
        $sql = implode("\n", array_column($this->connection->calls, 'sql'));
        self::assertStringContainsString('SET [IsDeleted] = 1', $sql);
        self::assertStringNotContainsString('DELETE FROM', $sql, 'a soft delete never removes the row');
        self::assertSame(1, substr_count($sql, 'INSERT INTO [npr].[Activity]'));
    }

    public function test_the_list_reads_only_live_files_and_ids_are_checked(): void
    {
        $this->connection->queueFetch([$this->row()]);

        self::assertCount(1, $this->repository->list(self::REQUEST));
        self::assertStringContainsString('a.[IsDeleted] = 0', $this->connection->calls[0]['sql']);
        self::assertSame([], $this->repository->list('nope'));
        self::assertNull($this->repository->find('nope', self::FILE));
        self::assertNull($this->repository->add('nope', new NewNprAttachment('a', 'k', 1, 'm', 'd', 't', new NprPerson(1, 'a@b.c', 'A')), 'requester'));
    }
}
