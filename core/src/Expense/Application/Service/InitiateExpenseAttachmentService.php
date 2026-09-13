<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Service;

use TriCab\Core\Expense\Application\DTO\ExpenseActor;
use TriCab\Core\Expense\Application\Exception\ExpenseAccessDenied;
use TriCab\Core\Expense\Application\Exception\ExpenseAttachmentUnavailable;
use TriCab\Core\Expense\Application\Exception\ExpenseReceiptNotFound;
use TriCab\Core\Expense\Contract\ExpenseAttachmentStore;
use TriCab\Core\Expense\Contract\ExpenseReceiptRepository;
use TriCab\Core\Expense\Domain\Entity\ExpenseReceipt;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseReceiptPublicId;
use TriCab\Core\Expense\Module;

final class InitiateExpenseAttachmentService
{
    /** @var array<string, list<string>> */
    private const MIME_BY_EXTENSION = [
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'pdf' => ['application/pdf'],
        'heic' => ['image/heic', 'image/heif', 'application/octet-stream'],
        'heif' => ['image/heif', 'image/heic', 'application/octet-stream'],
    ];

    public function __construct(
        private readonly ExpenseAccessService $access,
        private readonly ExpenseReceiptRepository $receipts,
        private readonly ExpenseAttachmentStore $attachments,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(
        ExpenseActor $actor,
        string $publicId,
        string $fileName,
        string $contentType,
        int $declaredBytes,
        int $expiresInSeconds = 900,
    ): array {
        $profile = $this->access->requireActiveProfile($actor);
        $receipt = $this->requireMutable($actor, $this->access->canMutateOthersReceipts($actor, $profile), $publicId);

        if ($declaredBytes < 1 || $declaredBytes > Module::MAX_UPLOAD_BYTES) {
            throw ExpenseAttachmentUnavailable::invalidFile();
        }

        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if (!in_array($ext, Module::ALLOWED_EXTENSIONS, true)) {
            throw ExpenseAttachmentUnavailable::invalidFile();
        }
        $allowedMime = self::MIME_BY_EXTENSION[$ext] ?? [];
        $mime = strtolower(trim($contentType));
        if ($mime === '' || !in_array($mime, $allowedMime, true)) {
            throw ExpenseAttachmentUnavailable::invalidFile();
        }

        $login = preg_replace('/[^a-z0-9_-]+/i', '', explode('@', $actor->emailAddress)[0] ?? 'user') ?: 'user';
        $blobPath = sprintf(
            '%s/%s/%s/%s_%s.%s',
            gmdate('Y'),
            gmdate('F'),
            strtolower($login),
            strtolower($login),
            gmdate('Ymd_His'),
            $ext,
        );

        $ticket = $this->attachments->initiateWrite($blobPath, $mime, max(60, $expiresInSeconds));
        $ticket['fileName'] = $fileName;
        $ticket['contentType'] = $mime;
        $ticket['receiptPublicId'] = $receipt->publicId()->toString();
        $ticket['maxBytes'] = Module::MAX_UPLOAD_BYTES;

        return $ticket;
    }

    private function requireMutable(ExpenseActor $actor, bool $privileged, string $publicId): ExpenseReceipt
    {
        $receipt = $this->receipts->findByPublicId(ExpenseReceiptPublicId::fromString($publicId));
        if ($receipt === null || $receipt->isDeleted()) {
            throw ExpenseReceiptNotFound::forPublicId($publicId);
        }
        if (!$receipt->ownerUserId()->equals($actor->userId) && !$privileged) {
            throw ExpenseAccessDenied::viewForbidden();
        }

        return $receipt;
    }
}
