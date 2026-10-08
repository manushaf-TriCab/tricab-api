<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Shared\Attachments;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use TriCab\Core\Finance\Module;

/**
 * The finance file store's rules, framework-free:
 *
 *  - The bytes go under a path made from the app, the month and a generated id.
 *    The sender's file name is stored as data and is never part of a path, so two
 *    files with the same name are two files (Tri-AR overwrote same-named files).
 *  - There is no download by id alone. {@see download()} needs the app, the caller
 *    and that app's {@see FinanceRecordGuard}; a file on a record is served only if
 *    the guard says the caller may see the record, and a file not yet on a record
 *    only to the person who uploaded it. Everything else is "not found".
 *  - A file never put on a record is removed after a day by
 *    {@see FinanceUnattachedAttachmentsJob}; deleting a record's files is
 *    {@see removeRecordFiles()}.
 *
 * The app's own route holds the app's permission check; this holds the rest.
 */
final class FinanceAttachmentService
{
    /** @var Closure(): DateTimeImmutable */
    private readonly Closure $now;

    /** @var Closure(): string */
    private readonly Closure $newId;

    /**
     * @param (callable(): DateTimeImmutable)|null $now
     * @param (callable(): string)|null $newId new 32-hex ids
     */
    public function __construct(
        private readonly FinanceAttachmentStore $store,
        ?callable $now = null,
        ?callable $newId = null,
    ) {
        $this->now = Closure::fromCallable($now ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC')));
        $this->newId = Closure::fromCallable($newId ?? static fn (): string => bin2hex(random_bytes(16)));
    }

    /** Stores a file that belongs to no record yet. */
    public function upload(string $application, string $originalName, string $contentType, string $bytes, ?int $uploaderUserId): FinanceAttachment
    {
        FinanceAttachmentRules::checkApplication($application);
        $name = FinanceAttachmentRules::cleanName($originalName);
        $type = FinanceAttachmentRules::cleanContentType($contentType);
        FinanceAttachmentRules::checkBytes($bytes);

        $at = ($this->now)();
        $id = ($this->newId)();
        $attachment = new FinanceAttachment(
            $id,
            $application,
            null,
            null,
            FinanceAttachmentRules::blobPath($application, $id, $at),
            $name,
            $type,
            strlen($bytes),
            hash('sha256', $bytes),
            $uploaderUserId,
            $at,
            null,
        );
        $this->store->put($attachment, $bytes);

        return $attachment;
    }

    /**
     * Puts an uploaded file on its record. Only the person who uploaded it may, and
     * only once; anything else is "not found".
     */
    public function attach(string $application, string $id, string $recordType, string $recordId, ?int $actorUserId): FinanceAttachment
    {
        FinanceAttachmentRules::checkApplication($application);
        FinanceAttachmentRules::checkRecord($recordType, $recordId);

        $found = $this->store->find($application, $id);
        if ($found === null || $found->isAttached() || $found->uploadedByUserId !== $actorUserId) {
            throw new FinanceAttachmentNotFound();
        }
        $at = ($this->now)();
        if (!$this->store->attach($application, $id, $recordType, $recordId, $at)) {
            throw new FinanceAttachmentNotFound();
        }

        return $found->withRecord($recordType, $recordId, $at);
    }

    /** @return list<FinanceAttachment> */
    public function forRecord(string $application, string $recordType, string $recordId): array
    {
        FinanceAttachmentRules::checkApplication($application);
        FinanceAttachmentRules::checkRecord($recordType, $recordId);

        return $this->store->forRecord($application, $recordType, $recordId);
    }

    /**
     * The one way to read a file. `$viewerUserId` is the signed-in person; `$guard` is
     * the calling app's own record check.
     *
     * @throws FinanceAttachmentNotFound when there is no such file for this app, the guard
     *         refuses the record, the file is not on a record and the viewer did not upload
     *         it, or its bytes are gone
     */
    public function download(string $application, string $id, ?int $viewerUserId, FinanceRecordGuard $guard): FinanceAttachmentFile
    {
        if (!isset(Module::APPS[$application])) {
            throw new FinanceAttachmentNotFound();
        }
        $found = $this->store->find($application, $id);
        if ($found === null) {
            throw new FinanceAttachmentNotFound();
        }
        if ($found->recordType !== null && $found->recordId !== null) {
            if (!$guard->maySee($application, $found->recordType, $found->recordId)) {
                throw new FinanceAttachmentNotFound();
            }
        } elseif ($viewerUserId === null || $found->uploadedByUserId !== $viewerUserId) {
            throw new FinanceAttachmentNotFound();
        }
        $bytes = $this->store->bytes($found);
        if ($bytes === null) {
            throw new FinanceAttachmentNotFound();
        }

        return new FinanceAttachmentFile($found, $bytes);
    }

    /** Deleting a record deletes its files. Returns how many. */
    public function removeRecordFiles(string $application, string $recordType, string $recordId): int
    {
        FinanceAttachmentRules::checkApplication($application);
        FinanceAttachmentRules::checkRecord($recordType, $recordId);

        return $this->store->deleteForRecord($application, $recordType, $recordId);
    }

    /** Removes one file (a person taking it off a record, or discarding an upload). False when there is none. */
    public function remove(string $application, string $id): bool
    {
        FinanceAttachmentRules::checkApplication($application);

        return $this->store->delete($application, $id);
    }
}
