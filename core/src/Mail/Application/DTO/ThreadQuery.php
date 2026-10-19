<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Application\DTO;

use TriCab\Core\Mail\Domain\Exception\MailInputInvalid;
use TriCab\Core\Mail\Domain\MailVocabulary;

/** `MailThreadQuery`, parsed and bounded. The cursor is an opaque offset. */
final readonly class ThreadQuery
{
    public function __construct(
        public string $queue = 'all',
        public string $search = '',
        public string $sort = 'latest',
        public bool $unreadOnly = false,
        public string $attachments = 'any',
        public int $offset = 0,
        public int $pageSize = MailVocabulary::DEFAULT_PAGE_SIZE,
    ) {
    }

    /** @param array<string, mixed> $query the request's query string */
    public static function fromInput(array $query): self
    {
        $queue = self::text($query['queue'] ?? 'all');
        $sort = self::text($query['sort'] ?? 'latest');
        $attachments = self::text($query['attachments'] ?? 'any');
        if (!in_array($queue, MailVocabulary::QUEUES, true)) {
            throw new MailInputInvalid('Unknown queue.');
        }
        if (!in_array($sort, MailVocabulary::SORTS, true)) {
            throw new MailInputInvalid('Unknown sort.');
        }
        if (!in_array($attachments, MailVocabulary::ATTACHMENT_FILTERS, true)) {
            throw new MailInputInvalid('Unknown attachment filter.');
        }
        $size = $query['pageSize'] ?? MailVocabulary::DEFAULT_PAGE_SIZE;
        if (!is_numeric($size) || (int) $size < 1 || (int) $size > MailVocabulary::MAX_PAGE_SIZE) {
            throw new MailInputInvalid(sprintf('pageSize must be between 1 and %d.', MailVocabulary::MAX_PAGE_SIZE));
        }
        $cursor = $query['cursor'] ?? null;

        return new self(
            $queue,
            mb_substr(trim(self::text($query['search'] ?? '')), 0, 200),
            $sort,
            in_array(strtolower(self::text($query['unreadOnly'] ?? '')), ['1', 'true'], true),
            $attachments,
            is_string($cursor) && $cursor !== '' ? self::decodeCursor($cursor) : 0,
            (int) $size,
        );
    }

    public static function encodeCursor(int $offset): string
    {
        return rtrim(strtr(base64_encode('o' . $offset), '+/', '-_'), '=');
    }

    private static function decodeCursor(string $cursor): int
    {
        $raw = base64_decode(strtr($cursor, '-_', '+/'), true);
        if ($raw === false || preg_match('/^o(\d{1,9})$/', $raw, $m) !== 1) {
            throw new MailInputInvalid('That page cursor is not one this inbox gave.');
        }

        return (int) $m[1];
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
