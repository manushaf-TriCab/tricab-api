<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap;

use DateTimeImmutable;
use TriCab\Core\Finance\Ap\Contract\ApEmailStore;
use TriCab\Core\Finance\Ap\Domain\ApConflict;
use TriCab\Core\Finance\Ap\Domain\ApEmail;
use TriCab\Core\Finance\Ap\Domain\ApEmailAttachment;
use TriCab\Core\Finance\Ap\Domain\ApEmailQuery;

/**
 * An email store that loses one race: the next `saveAttachment` finds the (email, Graph attachment id) pair already written by
 * another request and answers the unique key's `ApConflict`. Everything else is the real store's.
 */
final class RacingApEmailStore implements ApEmailStore
{
    public bool $failNextAttachmentSave = false;

    public function __construct(private readonly ApEmailStore $inner)
    {
    }

    public function transactional(callable $work): mixed
    {
        return $this->inner->transactional($work);
    }

    public function page(ApEmailQuery $query): array
    {
        return $this->inner->page($query);
    }

    public function counts(?DateTimeImmutable $start, ?DateTimeImmutable $end, ?array $mailboxes): array
    {
        return $this->inner->counts($start, $end, $mailboxes);
    }

    public function find(string $id): ?ApEmail
    {
        return $this->inner->find($id);
    }

    public function findMany(array $ids): array
    {
        return $this->inner->findMany($ids);
    }

    public function findByMessage(string $mailbox, string $graphMessageId): ?ApEmail
    {
        return $this->inner->findByMessage($mailbox, $graphMessageId);
    }

    public function insert(ApEmail $email): void
    {
        $this->inner->insert($email);
    }

    public function update(string $id, array $columns, DateTimeImmutable $at): void
    {
        $this->inner->update($id, $columns, $at);
    }

    public function bin(string $id, ?int $byUserId, string $by, DateTimeImmutable $at): void
    {
        $this->inner->bin($id, $byUserId, $by, $at);
    }

    public function restore(string $id, DateTimeImmutable $at): void
    {
        $this->inner->restore($id, $at);
    }

    public function duplicateInvoiceIdsByEmail(array $emailIds): array
    {
        return $this->inner->duplicateInvoiceIdsByEmail($emailIds);
    }

    public function attachmentsOf(string $emailId): array
    {
        return $this->inner->attachmentsOf($emailId);
    }

    public function findAttachment(string $emailId, ?string $graphAttachmentId): ?ApEmailAttachment
    {
        return $this->inner->findAttachment($emailId, $graphAttachmentId);
    }

    public function saveAttachment(ApEmailAttachment $attachment): void
    {
        if ($this->failNextAttachmentSave) {
            $this->failNextAttachmentSave = false;

            throw new ApConflict('Another request stored this file');
        }
        $this->inner->saveAttachment($attachment);
    }

    public function updateAttachment(string $id, array $columns, DateTimeImmutable $at): void
    {
        $this->inner->updateAttachment($id, $columns, $at);
    }
}
