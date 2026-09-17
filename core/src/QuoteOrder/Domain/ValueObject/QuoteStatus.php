<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Domain\ValueObject;

/**
 * Maps known legacy status codes only. Unknown codes preserve statusCode with
 * status=unknown / statusLabel=Unknown — no invented codes.
 */
final readonly class QuoteStatus
{
    public const DRAFT = 'draft';
    public const FINALIZED = 'finalized';
    public const OPEN = 'open';
    public const CLOSED = 'closed';
    public const UNKNOWN = 'unknown';

    private const MAP = [
        '1705' => [self::DRAFT, 'Draft'],
        '1703' => [self::FINALIZED, 'Finalized'],
        '1710' => [self::OPEN, 'Open'],
        '1711' => [self::CLOSED, 'Closed'],
    ];

    public function __construct(
        public string $status,
        public string $statusCode,
        public string $statusLabel,
    ) {
    }

    public static function fromCode(string|int|null $code): self
    {
        $statusCode = trim((string) $code);
        if ($statusCode === '') {
            return new self(self::UNKNOWN, '', 'Unknown');
        }

        $mapped = self::MAP[$statusCode] ?? null;
        if ($mapped === null) {
            return new self(self::UNKNOWN, $statusCode, 'Unknown');
        }

        return new self($mapped[0], $statusCode, $mapped[1]);
    }
}
