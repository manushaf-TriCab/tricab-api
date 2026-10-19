<?php

declare(strict_types=1);

namespace TriCab\Core\Npr\Infrastructure;

use TriCab\Core\Npr\Contract\NprAttachmentRepository;
use TriCab\Core\Npr\Contract\NprRequestRepository;
use TriCab\Core\Npr\Domain\NewNprAttachment;
use TriCab\Core\Npr\Domain\NprAttachment;
use TriCab\Core\Npr\Domain\NprPerson;
use TriCab\Core\Shared\Time\Clock;
use TriCab\Core\Shared\Time\SystemClock;

/** Attachment metadata in memory. With the in-memory request repository it also writes the Activity row. */
final class InMemoryNprAttachmentRepository implements NprAttachmentRepository
{
    /** @var array<string, NprAttachment> */
    private array $files = [];

    /** @var array<string, true> */
    private array $deleted = [];

    private int $sequence = 0;

    public function __construct(
        private readonly NprRequestRepository $requests,
        private readonly Clock $clock = new SystemClock(),
    ) {
    }

    public function add(string $requestPublicId, NewNprAttachment $new, string $actorRole): ?NprAttachment
    {
        $request = $this->requests->find($requestPublicId);
        if ($request === null) {
            return null;
        }
        $this->sequence++;
        $file = new NprAttachment(
            sprintf('aaaaaaaa-0000-4000-8000-%012d', $this->sequence),
            $request->publicId,
            $new->filename,
            $new->blobKey,
            $new->sizeBytes,
            $new->mimeType,
            $new->documentType,
            $new->title,
            $new->uploadedBy,
            $this->clock->now(),
        );
        $this->files[$file->publicId] = $file;
        $this->log($request->publicId, $request->revision, $request->status, $new->uploadedBy, $actorRole, 'attachment_added', 'Attachment added: ' . $new->filename);

        return $file;
    }

    public function list(string $requestPublicId): array
    {
        return array_values(array_filter(
            $this->files,
            fn (NprAttachment $file): bool => $file->requestPublicId === strtolower($requestPublicId) && !isset($this->deleted[$file->publicId]),
        ));
    }

    public function find(string $requestPublicId, string $attachmentPublicId): ?NprAttachment
    {
        $file = $this->files[strtolower($attachmentPublicId)] ?? null;

        return $file !== null && $file->requestPublicId === strtolower($requestPublicId) && !isset($this->deleted[$file->publicId]) ? $file : null;
    }

    public function softDelete(string $requestPublicId, string $attachmentPublicId, NprPerson $by, string $actorRole): ?NprAttachment
    {
        $file = $this->find($requestPublicId, $attachmentPublicId);
        $request = $this->requests->find($requestPublicId);
        if ($file === null || $request === null) {
            return null;
        }
        $this->deleted[$file->publicId] = true;
        $this->log($request->publicId, $request->revision, $request->status, $by, $actorRole, 'attachment_removed', 'Attachment removed: ' . $file->filename);

        return $file;
    }

    private function log(string $publicId, int $revision, \TriCab\Core\Npr\Domain\RequestStatus $status, NprPerson $actor, string $role, string $action, string $summary): void
    {
        if ($this->requests instanceof InMemoryNprRequestRepository) {
            $this->requests->appendActivity($publicId, new RecordedActivity($revision, $actor, $role, $action, $status, $status, $summary, [], $this->clock->now()));
        }
    }
}
