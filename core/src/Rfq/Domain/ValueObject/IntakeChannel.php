<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Domain\ValueObject;

/**
 * How an RFQ arrived. The source's draft `source` column: `web` for anything a
 * signed-in person pasted or uploaded, `email` for the mailbox worker.
 *
 * Customer self-service (`portal` in the web scaffold) is out of migration
 * scope and deliberately absent. What *kind* of input a line came from — pasted
 * text, a spreadsheet, an image — is per line, in {@see LineInputType}.
 */
final class IntakeChannel extends Vocabulary
{
    public const WEB = 'web';
    public const EMAIL = 'email';

    public static function values(): array
    {
        return [self::WEB, self::EMAIL];
    }

    protected static function fallback(): string
    {
        return self::WEB;
    }

    protected static function label(): string
    {
        return 'intake channel';
    }

    public static function web(): self
    {
        return new self(self::WEB);
    }
}
