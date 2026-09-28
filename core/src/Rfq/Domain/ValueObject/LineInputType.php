<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Domain\ValueObject;

/**
 * How one line was obtained — the source's `row.source.input_type`.
 *
 * The source leaves it empty for the deterministic parser; that reads as
 * `deterministic` here. Two values are *ungrounded*: nothing ties the line to
 * a cell or a text span in the document, so a person must approve it before
 * it syncs (`quote_readiness.row_push_block_reason`).
 */
final class LineInputType extends Vocabulary
{
    public const DETERMINISTIC = 'deterministic';
    public const TEXT_READER = 'text_reader';
    public const ATTACHMENT_READER = 'attachment_reader';
    public const EMAIL_BODY = 'email_body';
    public const EMAIL_HTML_TABLE = 'email_html_table';
    public const VISION_OCR = 'vision_ocr';
    public const READER_UNGROUNDED = 'reader_ungrounded';
    public const MANUAL = 'manual';
    public const UNKNOWN = 'unknown';

    /**
     * Needs a person's approval before sync. `unknown` is here because a line
     * whose origin nobody can state is not grounded either.
     */
    private const UNGROUNDED = [self::VISION_OCR, self::READER_UNGROUNDED, self::UNKNOWN];

    public static function values(): array
    {
        return [
            self::DETERMINISTIC,
            self::TEXT_READER,
            self::ATTACHMENT_READER,
            self::EMAIL_BODY,
            self::EMAIL_HTML_TABLE,
            self::VISION_OCR,
            self::READER_UNGROUNDED,
            self::MANUAL,
            self::UNKNOWN,
        ];
    }

    protected static function fallback(): string
    {
        return self::UNKNOWN;
    }

    protected static function aliases(): array
    {
        return ['' => self::DETERMINISTIC];
    }

    protected static function label(): string
    {
        return 'line input type';
    }

    /**
     * Reads the source's `row.source.input_type`, where a missing key and an
     * empty string both mean the deterministic parser. Distinct from
     * {@see fromStorage()}, which reads a missing value here as `unknown` —
     * a platform row always records its origin, so absence is a defect.
     */
    public static function fromSource(mixed $value): self
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return new self(self::DETERMINISTIC);
        }

        return self::fromStorage($value);
    }

    public function isUngrounded(): bool
    {
        return $this->isOneOf(self::UNGROUNDED);
    }
}
