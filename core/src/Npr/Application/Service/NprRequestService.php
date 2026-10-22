<?php

declare(strict_types=1);

namespace TriCab\Core\Npr\Application\Service;

use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Npr\Contract\NprBranchDirectory;
use TriCab\Core\Npr\Contract\NprRequesterDirectory;
use TriCab\Core\Npr\Contract\NprRequestRepository;
use TriCab\Core\Npr\Domain\Exception\InvalidNprInput;
use TriCab\Core\Npr\Domain\Exception\NprRequestConflict;
use TriCab\Core\Npr\Domain\Exception\NprRequestNotEditable;
use TriCab\Core\Npr\Domain\NewNprRequest;
use TriCab\Core\Npr\Domain\NprDraftChange;
use TriCab\Core\Npr\Domain\NprRequest;
use TriCab\Core\Npr\Infrastructure\NullNprRequesterDirectory;

/**
 * Drafts and reads (plan N1). Port of the npr-portal's `RequestService`
 * createDraft, saveDraft, getRequest and listRequests; submit, endorse,
 * return, screening, routing, email and attachments are N2.
 *
 * The requester is the authenticated user, never a field of the body. A
 * request that is not the caller's own is answered as "not found" (null), the
 * same as one that does not exist.
 */
final class NprRequestService
{
    private const DEFAULT_TITLE = 'Untitled NPD Request';
    private const DEFAULT_URGENCY = 'standard';

    public function __construct(
        private readonly NprRequestRepository $requests,
        private readonly NprBranchDirectory $branches,
        private readonly NprAccessPolicy $policy,
        private readonly NprRequesterDirectory $directory = new NullNprRequesterDirectory(),
    ) {
    }

    /**
     * Opens a draft. With no variants it starts with the source's default one.
     * Region / branch and contact number start from the roster and the staff
     * directory unless the body answers them; job title is left to the person.
     *
     * @throws InvalidNprInput
     */
    public function createDraft(AuthenticatedUser $user, mixed $answers, mixed $variants): NprRequestView
    {
        $answers = self::answers($answers);
        $variants = self::variants($variants);
        $branch = $this->branches->branchForEmail($user->emailAddress);
        $answers = $this->prefilled($answers, $user->emailAddress, $branch);

        $created = $this->requests->create(new NewNprRequest(
            $user->userId,
            strtolower($user->emailAddress),
            $user->displayName,
            $branch,
            self::text($answers, 'job_title', self::DEFAULT_TITLE),
            self::text($answers, 'customer_name', ''),
            self::text($answers, 'urgency_level', self::DEFAULT_URGENCY),
            $answers,
            $variants === [] ? [['id' => 'var_1', 'label' => 'Standard Construction', 'answers' => []]] : $variants,
        ));

        return $this->view($user, $created);
    }

    /**
     * Saves a draft the caller owns. `$expectedVersion` is the `version` the
     * client last read.
     *
     * @return NprRequestView|null null when the request is not the caller's
     * @throws InvalidNprInput
     * @throws NprRequestConflict
     * @throws NprRequestNotEditable
     */
    public function saveDraft(AuthenticatedUser $user, string $publicId, string $expectedVersion, mixed $answers, mixed $variants, ?string $changeSummary): ?NprRequestView
    {
        $current = $this->requests->find($publicId);
        if ($current === null || !$this->policy->canReadOwn($user, $current)) {
            return null;
        }
        if (!$this->policy->canEdit($user, $current)) {
            // Own and readable, but past editing (or without the create grant).
            throw new NprRequestNotEditable($current->status->value);
        }

        $answers = self::answers($answers);
        $variants = self::variants($variants);
        $summary = $changeSummary !== null && trim($changeSummary) !== '' ? trim($changeSummary) : null;

        $saved = $this->requests->saveDraft($publicId, $expectedVersion, new NprDraftChange(
            $user->userId,
            strtolower($user->emailAddress),
            $user->displayName,
            self::text($answers, 'job_title', $current->title),
            self::text($answers, 'customer_name', $current->customerName),
            self::text($answers, 'urgency_level', $current->urgencyLevel),
            $answers,
            $variants,
            $summary,
        ));

        return $saved === null ? null : $this->view($user, $saved);
    }

    /** The caller's own request, or null. */
    public function getOwn(AuthenticatedUser $user, string $publicId): ?NprRequestView
    {
        $request = $this->requests->find($publicId);

        return $request !== null && $this->policy->canReadOwn($user, $request) ? $this->view($user, $request) : null;
    }

    /** @return list<NprRequestView> the caller's own requests, newest first */
    public function listOwn(AuthenticatedUser $user): array
    {
        return array_map(fn (NprRequest $request): NprRequestView => $this->view($user, $request), $this->requests->listForRequester($user->userId));
    }

    /** A request that has reached TSD, or null (missing, not yet with TSD, or no NPR.TSD.READ). */
    public function getForTsd(AuthenticatedUser $user, string $publicId): ?NprRequestView
    {
        $request = $this->requests->find($publicId);

        return $request !== null && $this->policy->canReadAsTsd($user, $request) ? $this->view($user, $request) : null;
    }

    /** @return list<NprRequestView> requests with TSD or later; empty without NPR.TSD.READ */
    public function listForTsd(AuthenticatedUser $user): array
    {
        if (!NprAccessPolicy::holds($user, \TriCab\Core\Npr\Module::PERMISSION_TSD_READ)) {
            return [];
        }

        return array_map(fn (NprRequest $request): NprRequestView => $this->view($user, $request), $this->requests->listForTsd());
    }

    private function view(AuthenticatedUser $user, NprRequest $request): NprRequestView
    {
        return new NprRequestView($request, $this->policy->allowedActions($user, $request));
    }

    /**
     * @return array<string, mixed>
     * @throws InvalidNprInput
     */
    private static function answers(mixed $answers): array
    {
        if ($answers === null) {
            return [];
        }
        if (!is_array($answers) || ($answers !== [] && array_is_list($answers))) {
            throw new InvalidNprInput('answers must be an object keyed by field id.');
        }
        foreach ($answers as $fieldId => $answer) {
            if (!is_string($fieldId) || !is_array($answer)) {
                throw new InvalidNprInput('Every answer must be an object with a state.');
            }
        }

        /** @var array<string, mixed> $answers */
        return $answers;
    }

    /**
     * Each variant keeps its answers; an id and label are filled in when missing.
     *
     * @return list<array<string, mixed>>
     * @throws InvalidNprInput
     */
    private static function variants(mixed $variants): array
    {
        if ($variants === null) {
            return [];
        }
        if (!is_array($variants) || !array_is_list($variants)) {
            throw new InvalidNprInput('variants must be a list.');
        }
        $clean = [];
        foreach ($variants as $index => $variant) {
            if (!is_array($variant)) {
                throw new InvalidNprInput('Every variant must be an object.');
            }
            $answers = $variant['answers'] ?? [];
            if (!is_array($answers) || ($answers !== [] && array_is_list($answers))) {
                throw new InvalidNprInput('A variant answers must be an object keyed by field id.');
            }
            $id = $variant['id'] ?? null;
            $variant['id'] = is_string($id) && trim($id) !== '' ? trim($id) : 'var_' . ($index + 1);
            $label = $variant['label'] ?? null;
            $variant['label'] = is_string($label) && trim($label) !== '' ? trim($label) : 'Variant ' . ($index + 1);
            $variant['answers'] = $answers;
            /** @var array<string, mixed> $variant */
            $clean[] = $variant;
        }

        return $clean;
    }

    /**
     * Fills `branch` (the roster's, else the directory's office) and
     * `contact_number` (the directory's phone) where the body left them empty.
     *
     * @param array<string, mixed> $answers
     * @return array<string, mixed>
     */
    private function prefilled(array $answers, string $email, string $rosterBranch): array
    {
        $missingBranch = self::text($answers, 'branch', '') === '';
        $missingPhone = self::text($answers, 'contact_number', '') === '';
        if (!$missingBranch && !$missingPhone) {
            return $answers;
        }
        $contact = $this->directory->contactFor($email);
        $branch = $rosterBranch !== NprBranchDirectory::UNASSIGNED ? $rosterBranch : $contact?->officeLocation;
        if ($missingBranch && $branch !== null && trim($branch) !== '') {
            $answers['branch'] = ['state' => 'provided', 'value' => trim($branch)];
        }
        $phone = $contact?->phone;
        if ($missingPhone && $phone !== null && trim($phone) !== '') {
            $answers['contact_number'] = ['state' => 'provided', 'value' => trim($phone)];
        }

        return $answers;
    }

    /** The `value` of a provided text answer, else `$fallback`; capped at the column's 255. */
    private static function text(array $answers, string $fieldId, string $fallback): string
    {
        $answer = $answers[$fieldId] ?? null;
        $value = is_array($answer) ? ($answer['value'] ?? null) : null;
        $text = is_scalar($value) ? trim((string) $value) : '';

        return mb_substr($text !== '' ? $text : $fallback, 0, 255);
    }
}
