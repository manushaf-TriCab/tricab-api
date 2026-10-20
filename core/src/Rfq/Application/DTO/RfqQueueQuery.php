<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Application\DTO;

use TriCab\Core\Rfq\Domain\Exception\InvalidRfqValue;
use TriCab\Core\Rfq\Domain\ValueObject\Priority;
use TriCab\Core\Rfq\Domain\ValueObject\WorkflowStatus;

/**
 * The filters a caller may put on the queue. Every one only narrows.
 *
 * Everyone who reads RFQs reads all of them, so none of these is an access
 * decision. `branch` is a comma-separated list of branch codes, compared
 * upper-cased; `none` selects the RFQs that carry no branch. `mine=1` narrows
 * to the caller's own assigned work. `assignee` is a platform user id, or
 * `none` for the RFQs nobody is assigned. `fourJs` is `sent` (the RFQ carries
 * a 4js quote number, as the queue's 4js column shows) or `notSent`.
 */
final class RfqQueueQuery
{
    public const ARCHIVED_EXCLUDE = 'exclude';
    public const ARCHIVED_INCLUDE = 'include';
    public const ARCHIVED_ONLY = 'only';

    public const DEFAULT_PAGE_SIZE = 25;
    public const MAX_PAGE_SIZE = 100;
    public const MAX_SEARCH_LENGTH = 200;

    /** The branch filter value for RFQs that carry no branch. */
    public const BRANCH_NONE = 'none';

    /** The assignee filter value for RFQs nobody is assigned. */
    public const ASSIGNEE_NONE = 'none';

    public const FOUR_JS_ANY = '';
    public const FOUR_JS_SENT = 'sent';
    public const FOUR_JS_NOT_SENT = 'notSent';

    private const BRANCH_PATTERN = '/^[A-Z0-9_-]{1,32}$/';

    /**
     * @param list<WorkflowStatus> $statuses empty = any
     * @param list<Priority> $priorities empty = any
     * @param list<string> $branches upper-cased codes, or `BRANCH_NONE`; empty = any
     * @param int|string|null $assignee a user id, `ASSIGNEE_NONE`, or null = any
     * @param string $fourJs one of the `FOUR_JS_*` values
     */
    public function __construct(
        public readonly string $search,
        public readonly array $statuses,
        public readonly array $priorities,
        public readonly string $archived,
        public readonly bool $mineOnly,
        public readonly int $page,
        public readonly int $pageSize,
        public readonly array $branches = [],
        public readonly int|string|null $assignee = null,
        public readonly string $fourJs = self::FOUR_JS_ANY,
    ) {
    }

    public static function defaults(): self
    {
        return new self('', [], [], self::ARCHIVED_EXCLUDE, false, 1, self::DEFAULT_PAGE_SIZE);
    }

    /**
     * Strict: an unknown status or an out-of-range page is a 400, not a
     * silently ignored filter that would show the wrong rows.
     *
     * @param array<array-key, mixed> $params
     *
     * @throws InvalidRfqValue
     */
    public static function fromQueryParams(array $params): self
    {
        $search = self::string($params, 'q');
        if (mb_strlen($search) > self::MAX_SEARCH_LENGTH) {
            throw InvalidRfqValue::outOfRange('search length', mb_strlen($search), 0, self::MAX_SEARCH_LENGTH);
        }

        $statuses = array_map(
            static fn (string $v): WorkflowStatus => WorkflowStatus::fromString($v),
            self::csv($params, 'status'),
        );
        $priorities = array_map(
            static fn (string $v): Priority => Priority::fromString($v),
            self::csv($params, 'priority'),
        );

        $archived = self::string($params, 'archived');
        $archived = $archived === '' ? self::ARCHIVED_EXCLUDE : strtolower($archived);
        $allowedArchived = [self::ARCHIVED_EXCLUDE, self::ARCHIVED_INCLUDE, self::ARCHIVED_ONLY];
        if (!in_array($archived, $allowedArchived, true)) {
            throw InvalidRfqValue::forOneOf('archived filter', $archived, $allowedArchived);
        }

        $mine = strtolower(self::string($params, 'mine'));
        if (!in_array($mine, ['', '0', '1', 'true', 'false'], true)) {
            throw InvalidRfqValue::forOneOf('mine filter', $mine, ['1', '0']);
        }

        $branches = [];
        foreach (self::csv($params, 'branch') as $branch) {
            $branch = strtolower($branch) === self::BRANCH_NONE ? self::BRANCH_NONE : strtoupper($branch);
            if ($branch !== self::BRANCH_NONE && preg_match(self::BRANCH_PATTERN, $branch) !== 1) {
                throw InvalidRfqValue::forOneOf('branch filter', $branch, ['a branch code', self::BRANCH_NONE]);
            }
            $branches[] = $branch;
        }
        $assignee = self::string($params, 'assignee');
        if ($assignee === '') {
            $assignee = null;
        } elseif (strtolower($assignee) === self::ASSIGNEE_NONE) {
            $assignee = self::ASSIGNEE_NONE;
        } elseif (preg_match('/^[1-9]\d{0,9}$/', $assignee) === 1 && (int) $assignee <= 2_147_483_647) {
            $assignee = (int) $assignee;
        } else {
            throw InvalidRfqValue::forOneOf('assignee filter', $assignee, ['a user id', self::ASSIGNEE_NONE]);
        }

        $fourJs = self::string($params, 'fourJs');
        $allowedFourJs = [self::FOUR_JS_SENT, self::FOUR_JS_NOT_SENT];
        if ($fourJs !== self::FOUR_JS_ANY && !in_array($fourJs, $allowedFourJs, true)) {
            throw InvalidRfqValue::forOneOf('4js filter', $fourJs, $allowedFourJs);
        }

        $page = self::int($params, 'page', 1);
        if ($page < 1 || $page > 10_000) {
            throw InvalidRfqValue::outOfRange('page', $page, 1, 10_000);
        }

        $pageSize = self::int($params, 'pageSize', self::DEFAULT_PAGE_SIZE);
        if ($pageSize < 1 || $pageSize > self::MAX_PAGE_SIZE) {
            throw InvalidRfqValue::outOfRange('pageSize', $pageSize, 1, self::MAX_PAGE_SIZE);
        }

        return new self(
            $search,
            $statuses,
            $priorities,
            $archived,
            in_array($mine, ['1', 'true'], true),
            $page,
            $pageSize,
            array_values(array_unique($branches)),
            $assignee,
            $fourJs,
        );
    }

    /** @param array<array-key, mixed> $params */
    private static function string(array $params, string $key): string
    {
        $value = $params[$key] ?? '';

        return is_string($value) ? trim($value) : '';
    }

    /**
     * @param array<array-key, mixed> $params
     * @return list<string>
     */
    private static function csv(array $params, string $key): array
    {
        $raw = self::string($params, $key);
        if ($raw === '') {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map('trim', explode(',', $raw)),
            static fn (string $v): bool => $v !== '',
        )));
    }

    /** @param array<array-key, mixed> $params */
    private static function int(array $params, string $key, int $default): int
    {
        $raw = self::string($params, $key);
        if ($raw === '') {
            return $default;
        }

        if (preg_match('/^\d{1,6}$/', $raw) !== 1) {
            throw InvalidRfqValue::forOneOf($key, $raw, ['a whole number']);
        }

        return (int) $raw;
    }
}
