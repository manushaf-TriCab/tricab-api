<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\DTO\Admin;

use InvalidArgumentException;
use TriCab\Core\Onboarding\Domain\ValueObject\AdminEventType;

/**
 * One row of `[audit].[OnboardingAdminEvents]`, before it is written.
 *
 * ## The details are an allow-list, enforced here
 *
 * Invariant 13 wants actor, action, subject, time and result "without sensitive
 * payloads". Leaving that to each caller's care is how a form value ends up in
 * an audit table. So {@see self::DETAIL_KEYS} is every key a detail may use, a
 * value may only be a scalar, null or a list of scalars, and anything else is a
 * programming error thrown at construction — before a row exists to leak.
 *
 * None of the keys can hold a name, an email address, an answer, a form value
 * or text a person typed. Adding one that can is a change to this list, which
 * is where a reviewer will see it.
 */
final readonly class OnboardingAdminEvent
{
    public const OUTCOME_SUCCEEDED = 'succeeded';
    public const OUTCOME_REFUSED = 'refused';

    public const DETAIL_KEYS = [
        'itemType',
        'itemPublicId',
        'versionPublicId',
        'versionNumber',
        'status',
        'fromStatus',
        'toStatus',
        'revisionNumber',
        'accountCreated',
        'canSignIn',
        'changedFields',
        'fromLastDay',
        'toLastDay',
        'fromDueDate',
        'toDueDate',
        'dueDate',
        'reason',
        'allocatedCount',
        'withdrawnCount',
        'keptCount',
        'dueDateChangedCount',
        // 4.4 — counts and flags about content, never the content itself.
        'accessGroup',
        'isActive',
        'slideCount',
        'questionCount',
        'blockCount',
        'fieldCount',
        'copiedFromVersionNumber',
        'retiredVersionNumber',
        'supersedesWorkInProgress',
        'reissuedCount',
        'fromAssignmentPublicId',
    ];

    /**
     * @param array<string, scalar|null|list<scalar>> $details
     */
    private function __construct(
        public string $eventType,
        public string $outcome,
        public ?int $subjectUserId,
        public ?string $targetPublicId,
        public string $summary,
        public array $details,
        public string $permissionCode,
    ) {
    }

    /**
     * @param array<string, scalar|null|list<scalar>> $details
     */
    public static function succeeded(
        string $eventType,
        ?int $subjectUserId,
        ?string $targetPublicId,
        string $summary,
        array $details,
        string $permissionCode,
    ): self {
        return self::make($eventType, self::OUTCOME_SUCCEEDED, $subjectUserId, $targetPublicId, $summary, $details, $permissionCode);
    }

    /**
     * @param array<string, scalar|null|list<scalar>> $details
     */
    public static function refused(
        string $eventType,
        ?int $subjectUserId,
        ?string $targetPublicId,
        string $summary,
        array $details,
        string $permissionCode,
    ): self {
        return self::make($eventType, self::OUTCOME_REFUSED, $subjectUserId, $targetPublicId, $summary, $details, $permissionCode);
    }

    /**
     * @param array<string, mixed> $details
     */
    private static function make(
        string $eventType,
        string $outcome,
        ?int $subjectUserId,
        ?string $targetPublicId,
        string $summary,
        array $details,
        string $permissionCode,
    ): self {
        AdminEventType::assertKnown($eventType);

        $clean = [];
        foreach ($details as $key => $value) {
            if (!in_array($key, self::DETAIL_KEYS, true)) {
                throw new InvalidArgumentException(sprintf('"%s" is not an allowed onboarding audit detail.', $key));
            }
            if (!self::isAllowedValue($value)) {
                throw new InvalidArgumentException(sprintf('Audit detail "%s" must be a scalar, null or a list of scalars.', $key));
            }
            /** @var scalar|null|list<scalar> $value */
            $clean[$key] = $value;
        }

        return new self(
            $eventType,
            $outcome,
            $subjectUserId,
            $targetPublicId,
            mb_substr(trim($summary), 0, 500),
            $clean,
            $permissionCode,
        );
    }

    private static function isAllowedValue(mixed $value): bool
    {
        if ($value === null || is_scalar($value)) {
            return true;
        }
        if (!is_array($value) || !array_is_list($value)) {
            return false;
        }
        foreach ($value as $entry) {
            if (!is_scalar($entry)) {
                return false;
            }
        }

        return true;
    }

    public function detailsJson(): ?string
    {
        return $this->details === [] ? null : json_encode($this->details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
