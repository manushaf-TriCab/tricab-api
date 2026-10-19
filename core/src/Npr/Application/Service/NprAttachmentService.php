<?php

declare(strict_types=1);

namespace TriCab\Core\Npr\Application\Service;

use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Npr\Attachment\NprAttachmentRules;
use TriCab\Core\Npr\Contract\NprAttachmentRepository;
use TriCab\Core\Npr\Contract\NprAttachmentStore;
use TriCab\Core\Npr\Contract\NprRequestRepository;
use TriCab\Core\Npr\Domain\Exception\InvalidNprInput;
use TriCab\Core\Npr\Domain\Exception\NprAttachmentRejected;
use TriCab\Core\Npr\Domain\Exception\NprRequestNotEditable;
use TriCab\Core\Npr\Domain\NewNprAttachment;
use TriCab\Core\Npr\Domain\NprAttachment;
use TriCab\Core\Npr\Domain\NprPerson;
use TriCab\Core\Npr\Domain\NprRequest;
use TriCab\Core\Npr\Domain\RequestStatus;
use TriCab\Core\Npr\Module;

/**
 * Files on a request (plan N2.3). Three doors, each answering "not found" (null)
 * to someone with no part in the request:
 *
 *  - `own`:  the requester. Reads any time; uploads and removes only while Sales
 *            can edit (draft, returned), and removes only files they uploaded;
 *  - `team`: the assigned manager, read only;
 *  - `tsd`:  anyone who can read the TSD queue; with NPR.TSD.WORK also uploads,
 *            while the request is with TSD or awaiting screening.
 *
 * Each add and remove writes one Activity row (in the repository). Bytes go to
 * the private container under a generated name; removal is a soft delete that
 * keeps the row and the blob. A download is only ever served through this
 * service, so it is authenticated and request-scoped.
 */
final class NprAttachmentService
{
    public const SCOPE_OWN = 'own';
    public const SCOPE_TEAM = 'team';
    public const SCOPE_TSD = 'tsd';

    public function __construct(
        private readonly NprRequestRepository $requests,
        private readonly NprAttachmentRepository $attachments,
        private readonly NprAttachmentStore $store,
        private readonly NprAccessPolicy $policy,
    ) {
    }

    /**
     * For request detail responses: the caller already proved access to the request.
     *
     * @return list<NprAttachment>
     */
    public function forRequest(string $publicId): array
    {
        return $this->attachments->list($publicId);
    }

    /** @return list<NprAttachment>|null null when the caller has no part in the request */
    public function list(AuthenticatedUser $user, string $scope, string $publicId): ?array
    {
        $request = $this->readable($user, $scope, $publicId);

        return $request === null ? null : $this->attachments->list($request->publicId);
    }

    /**
     * @return NprAttachment|null null when the caller has no part in the request
     * @throws NprRequestNotEditable
     * @throws NprAttachmentRejected
     * @throws InvalidNprInput
     * @throws \TriCab\Core\Npr\Domain\Exception\NprAttachmentStorageUnavailable
     */
    public function upload(AuthenticatedUser $user, string $scope, string $publicId, string $filename, string $bytes, ?string $documentType, ?string $title): ?NprAttachment
    {
        $request = $this->readable($user, $scope, $publicId);
        if ($request === null) {
            return null;
        }
        $role = $this->assertCanUpload($user, $scope, $request);

        $name = self::cleanName($filename);
        $mime = NprAttachmentRules::check($name, $bytes);
        $type = self::documentType($documentType);

        $existing = $this->attachments->list($request->publicId);
        if (count($existing) >= NprAttachmentRules::MAX_FILES) {
            throw new NprAttachmentRejected(NprAttachmentRejected::TOO_MANY, sprintf('A request can hold at most %d attachments.', NprAttachmentRules::MAX_FILES));
        }
        $total = strlen($bytes) + array_sum(array_map(static fn (NprAttachment $file): int => $file->sizeBytes, $existing));
        if ($total > NprAttachmentRules::MAX_REQUEST_BYTES) {
            throw new NprAttachmentRejected(NprAttachmentRejected::REQUEST_TOO_LARGE, 'The attachments of a request may total at most 30 MiB.', 413);
        }

        $key = sprintf('%s/%s.%s', $request->publicId, bin2hex(random_bytes(12)), strtolower(pathinfo($name, PATHINFO_EXTENSION)));
        $this->store->put($key, $mime, $bytes);
        try {
            $saved = $this->attachments->add($request->publicId, new NewNprAttachment(
                $name,
                $key,
                strlen($bytes),
                $mime,
                $type,
                self::title($title, $name),
                new NprPerson($user->userId, strtolower($user->emailAddress), $user->displayName),
            ), $role);
        } catch (\Throwable $failure) {
            $this->store->discard($key);

            throw $failure;
        }
        if ($saved === null) {
            $this->store->discard($key);
        }

        return $saved;
    }

    /**
     * Soft delete by the requester, of their own file, while the request is editable.
     *
     * @return NprAttachment|null null when there is no such file for the caller
     * @throws NprRequestNotEditable
     */
    public function remove(AuthenticatedUser $user, string $publicId, string $attachmentPublicId): ?NprAttachment
    {
        $request = $this->readable($user, self::SCOPE_OWN, $publicId);
        if ($request === null) {
            return null;
        }
        $file = $this->attachments->find($request->publicId, $attachmentPublicId);
        if ($file === null || !$file->uploadedBy->is($user->userId)) {
            return null;
        }
        if (!$this->policy->canEdit($user, $request)) {
            throw new NprRequestNotEditable($request->status->value);
        }

        return $this->attachments->softDelete(
            $request->publicId,
            $attachmentPublicId,
            new NprPerson($user->userId, strtolower($user->emailAddress), $user->displayName),
            'requester',
        );
    }

    /**
     * @return array{file: NprAttachment, bytes: string}|null null when there is no such readable file
     * @throws \TriCab\Core\Npr\Domain\Exception\NprAttachmentStorageUnavailable
     */
    public function download(AuthenticatedUser $user, string $scope, string $publicId, string $attachmentPublicId): ?array
    {
        $request = $this->readable($user, $scope, $publicId);
        if ($request === null) {
            return null;
        }
        $file = $this->attachments->find($request->publicId, $attachmentPublicId);
        $bytes = $file === null ? null : $this->store->get($file->blobKey);

        return $file === null || $bytes === null ? null : ['file' => $file, 'bytes' => $bytes];
    }

    private function readable(AuthenticatedUser $user, string $scope, string $publicId): ?NprRequest
    {
        $request = $this->requests->find($publicId);
        if ($request === null) {
            return null;
        }

        return match ($scope) {
            self::SCOPE_OWN => $this->policy->canReadOwn($user, $request) ? $request : null,
            self::SCOPE_TEAM => $this->policy->canReadAsManager($user, $request) && $request->status !== RequestStatus::Draft ? $request : null,
            self::SCOPE_TSD => $this->policy->canReadAsTsd($user, $request) ? $request : null,
            default => null,
        };
    }

    /** @return string the Activity actor role */
    private function assertCanUpload(AuthenticatedUser $user, string $scope, NprRequest $request): string
    {
        if ($scope === self::SCOPE_OWN) {
            if (!$this->policy->canEdit($user, $request)) {
                throw new NprRequestNotEditable($request->status->value);
            }

            return 'requester';
        }
        if ($scope === self::SCOPE_TSD
            && NprAccessPolicy::holds($user, Module::PERMISSION_TSD_WORK)
            && in_array($request->status, [RequestStatus::WithTsd, RequestStatus::AwaitingScreening], true)) {
            return 'tsd';
        }

        throw new NprRequestNotEditable($request->status->value);
    }

    /** The base name only, control characters out, 255 characters at most (the column). */
    private static function cleanName(string $filename): string
    {
        $name = trim(basename(str_replace('\\', '/', $filename)));
        $name = (string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $name);
        if ($name === '' || $name === '.' || $name === '..') {
            throw new NprAttachmentRejected(NprAttachmentRejected::NO_FILE, 'The file needs a name.');
        }

        return mb_substr($name, -255);
    }

    /** @throws InvalidNprInput */
    private static function documentType(?string $value): string
    {
        $type = $value === null ? '' : trim($value);
        if ($type === '') {
            return 'datasheet';
        }
        if (preg_match('/^[A-Za-z0-9 _-]{1,64}$/', $type) !== 1) {
            throw new InvalidNprInput('documentType may use letters, digits, spaces, hyphens and underscores (up to 64).');
        }

        return $type;
    }

    private static function title(?string $title, string $fallback): string
    {
        $text = $title === null ? '' : trim($title);

        return mb_substr($text !== '' ? $text : $fallback, 0, 255);
    }
}
