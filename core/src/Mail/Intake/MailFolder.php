<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Intake;

/**
 * A mail folder the reader reads. `id` is Graph's folder id (or the well-known alias `inbox`, `deleteditems`);
 * `path` is the display path from the Inbox down ("Inbox/NZ PROJECTS - GUY"), used for logs and for excluding
 * folders by name.
 */
final readonly class MailFolder
{
    public const INBOX = 'inbox';

    public function __construct(public string $id, public string $name, public string $path)
    {
    }

    public static function inbox(): self
    {
        return new self(self::INBOX, 'Inbox', 'Inbox');
    }

    public function isInbox(): bool
    {
        return $this->id === self::INBOX;
    }

    /**
     * True when the folder, or any folder above it, is named in `$names` (compared lower case).
     *
     * @param list<string> $names
     */
    public function isExcluded(array $names): bool
    {
        if ($names === []) {
            return false;
        }
        foreach (explode('/', $this->path) as $segment) {
            if (in_array(strtolower(trim($segment)), $names, true)) {
                return true;
            }
        }

        return false;
    }
}
