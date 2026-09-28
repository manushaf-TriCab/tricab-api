<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Storage;

/**
 * A draft to create: the arguments of the source's `save_draft`.
 *
 * Ownership follows the source: the creator's email, else the first of
 * `owner_email`, `rep_email`, `created_by_email` in the email metadata. The
 * assignee is the owner unless given; an empty string means unassigned.
 */
final class NewDraft
{
    /**
     * @param array<string, mixed>       $quoteDetails
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed>|null  $emailMeta
     */
    public function __construct(
        public readonly string $source,
        public readonly string $label,
        public readonly array $quoteDetails,
        public readonly array $rows,
        public readonly ?array $emailMeta = null,
        public readonly string $creatorEmail = '',
        public readonly string $branch = '',
        public readonly ?string $assigneeEmail = null,
    ) {
    }

    public function owner(): string
    {
        $owner = StoreFormat::email($this->creatorEmail);
        if ($owner !== '') {
            return $owner;
        }
        foreach (['owner_email', 'rep_email', 'created_by_email'] as $key) {
            $owner = StoreFormat::email($this->emailMeta[$key] ?? null);
            if ($owner !== '') {
                return $owner;
            }
        }

        return '';
    }

    public function ownerBranch(): string
    {
        $branch = StoreFormat::branch($this->branch);

        return $branch !== '' ? $branch : StoreFormat::branch($this->emailMeta['owner_branch'] ?? null);
    }

    public function assignee(): string
    {
        return $this->assigneeEmail === null ? $this->owner() : StoreFormat::email($this->assigneeEmail);
    }
}
