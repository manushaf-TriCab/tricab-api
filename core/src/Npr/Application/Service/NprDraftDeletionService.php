<?php

declare(strict_types=1);

namespace TriCab\Core\Npr\Application\Service;

use Throwable;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Npr\Contract\NprAttachmentRepository;
use TriCab\Core\Npr\Contract\NprAttachmentStore;
use TriCab\Core\Npr\Contract\NprRequestRepository;
use TriCab\Core\Npr\Domain\Exception\NprRequestNotDeletable;

/**
 * Sales deleting their own never-submitted draft. The request and its child
 * rows go in one transaction (the repository); the blobs go afterwards, best
 * effort, so a storage failure leaves an orphan blob and never a half-deleted request.
 */
final class NprDraftDeletionService
{
    public function __construct(
        private readonly NprRequestRepository $requests,
        private readonly NprAttachmentRepository $attachments,
        private readonly NprAttachmentStore $store,
        private readonly NprAccessPolicy $policy,
    ) {
    }

    /**
     * @return bool false when the caller has no such request (missing, or not theirs)
     * @throws NprRequestNotDeletable
     */
    public function delete(AuthenticatedUser $user, string $publicId): bool
    {
        $request = $this->requests->find($publicId);
        if ($request === null || !$this->policy->canReadOwn($user, $request)) {
            return false;
        }
        if (!$this->policy->canDelete($user, $request)) {
            throw new NprRequestNotDeletable($request->status->value);
        }

        $keys = $this->attachments->allBlobKeys($request->publicId);
        if (!$this->requests->deleteNeverSubmitted($request->publicId)) {
            return false;
        }
        foreach ($keys as $key) {
            try {
                $this->store->discard($key);
            } catch (Throwable $failure) {
                error_log(sprintf('NPR: could not remove blob %s of deleted draft %s: %s', $key, $request->publicId, $failure->getMessage()));
            }
        }

        return true;
    }
}
