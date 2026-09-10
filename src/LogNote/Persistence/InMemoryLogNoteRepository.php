<?php

declare(strict_types=1);

namespace TriCab\Api\LogNote\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Core\Customer\Application\DTO\Pagination;
use TriCab\Core\Customer\Contract\CustomerRepository;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\LogNote\Application\DTO\LogNoteDetails;
use TriCab\Core\LogNote\Application\DTO\LogNoteListCriteria;
use TriCab\Core\LogNote\Application\DTO\LogNoteListResult;
use TriCab\Core\LogNote\Application\DTO\LogNoteSummary;
use TriCab\Core\LogNote\Contract\LogNoteRepository;
use TriCab\Core\LogNote\Domain\Entity\LogNote;
use TriCab\Core\LogNote\Domain\ValueObject\LogNoteId;
use TriCab\Core\LogNote\Domain\ValueObject\LogNotePublicId;

final class InMemoryLogNoteRepository implements LogNoteRepository
{
    /** @var list<LogNote> */
    private array $notes = [];

    private int $nextId = 1;

    public function __construct(
        private readonly CustomerRepository $customers,
        private readonly UserRepository $users,
    ) {
    }

    public function save(LogNote $note): LogNoteDetails
    {
        $saved = $note->id() === null ? $note->withId(LogNoteId::fromInt($this->nextId++)) : $note;
        $this->notes[] = $saved;

        $details = $this->toDetails($saved);
        if ($details === null) {
            throw new \RuntimeException('Unable to project saved log note.');
        }

        return $details;
    }

    public function findDetailsByPublicId(LogNotePublicId $publicId): ?LogNoteDetails
    {
        foreach ($this->notes as $note) {
            if ($note->publicId()->equals($publicId)) {
                return $this->toDetails($note);
            }
        }

        return null;
    }

    public function list(LogNoteListCriteria $criteria): LogNoteListResult
    {
        /** @var list<array{note: LogNote, details: LogNoteDetails}> $matched */
        $matched = [];
        foreach ($this->notes as $note) {
            $details = $this->toDetails($note);
            if ($details === null || !$this->matches($details, $note, $criteria)) {
                continue;
            }
            $matched[] = ['note' => $note, 'details' => $details];
        }

        usort(
            $matched,
            /**
             * @param array{note: LogNote, details: LogNoteDetails} $left
             * @param array{note: LogNote, details: LogNoteDetails} $right
             */
            static function (array $left, array $right) use ($criteria): int {
                $cmp = strcmp($left['details']->createdAtUtc, $right['details']->createdAtUtc);
                if ($cmp === 0) {
                    $leftId = $left['note']->id()?->toInt() ?? 0;
                    $rightId = $right['note']->id()?->toInt() ?? 0;
                    $cmp = $leftId <=> $rightId;
                }

                return $criteria->normalizedSortDirection() === LogNoteListCriteria::SORT_ASC ? $cmp : -$cmp;
            },
        );

        $total = count($matched);
        $offset = ($criteria->page - 1) * $criteria->pageSize;
        $pageItems = array_slice($matched, $offset, $criteria->pageSize);

        return new LogNoteListResult(
            items: array_map(
                /**
                 * @param array{note: LogNote, details: LogNoteDetails} $item
                 */
                static fn (array $item): LogNoteSummary => $item['details']->toSummary(),
                $pageItems,
            ),
            pagination: Pagination::fromTotals($criteria->page, $criteria->pageSize, $total),
        );
    }

    private function matches(LogNoteDetails $details, LogNote $note, LogNoteListCriteria $criteria): bool
    {
        if ($criteria->customerPublicId !== null
            && strtolower($details->customerPublicId) !== strtolower($criteria->customerPublicId)
        ) {
            return false;
        }

        if ($criteria->customerCode !== null
            && strcasecmp((string) $details->customerCode, $criteria->customerCode) !== 0
        ) {
            return false;
        }

        if ($criteria->interactionType !== null
            && $details->interactionTypeCode !== $criteria->interactionType
        ) {
            return false;
        }

        if ($criteria->category !== null && $details->categoryCode !== strtolower($criteria->category)) {
            return false;
        }

        if ($criteria->country !== null
            && strcasecmp((string) $details->countryCode, $criteria->country) !== 0
        ) {
            return false;
        }

        if ($criteria->territory !== null
            && strcasecmp((string) $details->territoryCode, $criteria->territory) !== 0
        ) {
            return false;
        }

        if ($criteria->createdBy !== null && !$this->matchesCreatedBy($details, $note, $criteria->createdBy)) {
            return false;
        }

        $created = $note->createdAtUtc();
        $from = $this->parseBoundary($criteria->from, false);
        if ($from !== null && $created < $from) {
            return false;
        }
        $to = $this->parseBoundary($criteria->to, true);
        if ($to !== null && $created > $to) {
            return false;
        }

        $search = $criteria->normalizedSearch();
        if ($search !== null) {
            $haystack = strtolower(implode(' ', array_filter([
                $details->body,
                $details->contactName,
                $details->customerName,
                $details->customerCode,
            ])));
            if (!str_contains($haystack, strtolower($search))) {
                return false;
            }
        }

        return true;
    }

    private function matchesCreatedBy(LogNoteDetails $details, LogNote $note, string $createdBy): bool
    {
        $needle = strtolower(trim($createdBy));
        if ($needle === '') {
            return true;
        }

        if (strtolower($details->createdByUserPublicId) === $needle) {
            return true;
        }

        $user = $this->users->findById($note->createdByUserId());
        $email = $user !== null ? strtolower($user->emailAddress()->toString()) : '';

        return str_contains(strtolower($details->createdByDisplayName), $needle)
            || ($email !== '' && str_contains($email, $needle));
    }

    private function parseBoundary(?string $value, bool $endOfDay): ?DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $raw = trim($value);
        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1) {
                $time = $endOfDay ? '23:59:59.999999' : '00:00:00.000000';

                return new DateTimeImmutable($raw . ' ' . $time, new DateTimeZone('UTC'));
            }

            return new DateTimeImmutable($raw, new DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }

    private function toDetails(LogNote $note): ?LogNoteDetails
    {
        $customer = $this->customers->findDetailsByPublicId($note->customerPublicId());
        if ($customer === null) {
            return null;
        }

        $user = $this->users->findById($note->createdByUserId());
        $createdByPublicId = $user?->publicId()->toString() ?? '';
        $createdByName = $user?->displayName() ?? 'Unknown';

        return new LogNoteDetails(
            publicId: $note->publicId()->toString(),
            customerPublicId: $customer->publicId,
            customerCode: $customer->customerCode,
            customerName: $customer->customerName,
            contactPublicId: null,
            contactName: $note->contactNameSnapshot(),
            contactPosition: $note->contactPositionSnapshot(),
            interactionTypeCode: $note->interactionType()->toString(),
            interactionTypeLabel: $note->interactionType()->label(),
            categoryCode: $note->category()->toString(),
            categoryLabel: $note->category()->label(),
            body: $note->body()->toString(),
            bodyPreview: $note->body()->preview(),
            callResultCode: $note->callResultCode(),
            isAiGenerated: $note->isAiGenerated(),
            followUpAt: $note->followUpAt() !== null
                ? AzureSqlValueMapper::formatIso8601($note->followUpAt())
                : null,
            createdByUserPublicId: $createdByPublicId,
            createdByDisplayName: $createdByName,
            createdAtUtc: AzureSqlValueMapper::formatIso8601($note->createdAtUtc()),
            countryCode: $customer->countryCode,
            territoryCode: $customer->territory,
        );
    }
}
