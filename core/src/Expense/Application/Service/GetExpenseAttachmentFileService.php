<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Service;

use TriCab\Core\Expense\Application\DTO\ExpenseActor;
use TriCab\Core\Expense\Application\DTO\ExpenseReceiptDetails;
use TriCab\Core\Expense\Application\Exception\ExpenseAccessDenied;
use TriCab\Core\Expense\Application\Exception\ExpenseAttachmentUnavailable;
use TriCab\Core\Expense\Application\Exception\ExpenseReceiptNotFound;
use TriCab\Core\Expense\Contract\ExpenseAttachmentStore;
use TriCab\Core\Expense\Contract\ExpenseProfileRepository;
use TriCab\Core\Expense\Contract\ExpenseReceiptRepository;
use TriCab\Core\Expense\Contract\LegacyExpenseReadRepository;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseAttachmentRef;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseReceiptPublicId;
use TriCab\Core\Expense\Domain\ValueObject\LegacyExpenseReceiptRef;
use TriCab\Core\Expense\Module;

final class GetExpenseAttachmentFileService
{
    public function __construct(
        private readonly ExpenseAccessService $access,
        private readonly ExpenseProfileRepository $profiles,
        private readonly ExpenseReceiptRepository $receipts,
        private readonly ExpenseAttachmentStore $attachments,
        private readonly LegacyExpenseReadRepository $legacyReceipts,
        private readonly LegacyExpenseIdentityResolver $legacyIdentity,
        private readonly bool $legacyReadEnabled = false,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(ExpenseActor $actor, string $publicId, int $expiresInSeconds = 300): array
    {
        if (!$actor->hasPermission(Module::PERMISSION_ACCESS)) {
            throw ExpenseAccessDenied::permissionRequired();
        }

        $legacyRef = LegacyExpenseReceiptRef::tryParse($publicId);
        if ($legacyRef !== null) {
            return $this->legacyAttachment($actor, $legacyRef, $expiresInSeconds);
        }

        $profile = $this->profiles->findByUserId($actor->userId);
        $receipt = $this->receipts->findByPublicId(ExpenseReceiptPublicId::fromString($publicId));
        if ($receipt === null || $receipt->isDeleted()) {
            throw ExpenseReceiptNotFound::forPublicId($publicId);
        }

        $isOwner = $receipt->ownerUserId()->equals($actor->userId);
        $isManager = $receipt->managerUserId() !== null && $receipt->managerUserId()->equals($actor->userId);
        if (!$isOwner && !$isManager && !($profile !== null && $this->access->canMutateOthersReceipts($actor, $profile))) {
            throw ExpenseAccessDenied::viewForbidden();
        }

        $ref = $receipt->attachment();
        if ($ref === null) {
            throw ExpenseAttachmentUnavailable::notFound();
        }

        $ticket = $this->attachments->issueAuthorizedRead($ref, max(60, $expiresInSeconds));
        if ($ticket === null) {
            throw ExpenseAttachmentUnavailable::legacyDualRead();
        }

        $ticket['fileName'] = $ref->fileName();
        $ticket['storageSource'] = $ref->storageSource();
        $ticket['dualReadClass'] = $ref->dualReadClass();

        return $ticket;
    }

    /**
     * @return array<string, mixed>
     */
    private function legacyAttachment(ExpenseActor $actor, LegacyExpenseReceiptRef $legacyRef, int $expiresInSeconds): array
    {
        if (!$this->legacyReadEnabled) {
            throw ExpenseReceiptNotFound::forPublicId($legacyRef->toPublicId());
        }

        $profile = $this->profiles->findByUserId($actor->userId);
        $ownerKeys = $this->legacyIdentity->ownerKeys($profile);
        if ($ownerKeys === []) {
            throw ExpenseReceiptNotFound::forPublicId($legacyRef->toPublicId());
        }

        $row = $this->legacyReceipts->findOwnedById($legacyRef->legacyReceiptId(), $ownerKeys);
        if ($row === null) {
            throw ExpenseReceiptNotFound::forPublicId($legacyRef->toPublicId());
        }

        $fileUrl = trim((string) ($row['file_url'] ?? ''));
        if ($fileUrl === '') {
            throw ExpenseAttachmentUnavailable::notFound();
        }

        // Server resolves URL from authorized row — never from client input.
        $ref = ExpenseAttachmentRef::fromLegacyUrl(
            $fileUrl,
            trim((string) ($row['file_name'] ?? '')) !== '' ? trim((string) $row['file_name']) : null,
        );

        $ticket = $this->attachments->issueAuthorizedRead($ref, max(60, $expiresInSeconds));
        if ($ticket === null) {
            // Controlled metadata response for WordPress / unresolved dual-read paths.
            if (str_starts_with(strtolower($fileUrl), 'http://') || str_starts_with(strtolower($fileUrl), 'https://')) {
                $expires = (new \DateTimeImmutable('now'))->modify('+' . max(60, $expiresInSeconds) . ' seconds');

                return [
                    'url' => $fileUrl,
                    'httpMethod' => 'GET',
                    'requiredHeaders' => [],
                    'expiresAtUtc' => $expires->format(DATE_ATOM),
                    'fileName' => $ref->fileName(),
                    'storageSource' => $ref->storageSource(),
                    'dualReadClass' => $ref->dualReadClass(),
                    'source' => ExpenseReceiptDetails::SOURCE_LEGACY,
                ];
            }

            throw ExpenseAttachmentUnavailable::legacyDualRead();
        }

        $ticket['fileName'] = $ref->fileName();
        $ticket['storageSource'] = $ref->storageSource();
        $ticket['dualReadClass'] = $ref->dualReadClass();
        $ticket['source'] = 'legacy';

        return $ticket;
    }
}
