<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Domain;

/**
 * The mailboxes one person may see in Tri-AP (decision Q11, `_mailbox_scope` / `_invoice_visible`
 * in the source).
 *
 * - No restriction: every mailbox. That is a person with no `finance.access_scopes` rows for the
 *   app, and every administrator (see {@see ApActor}).
 * - A restriction: exactly those mailboxes, plus what has none. An invoice that was captured by
 *   hand has no mailbox and is visible to everyone.
 *
 * Every Tri-AP use case that reads or changes an invoice or an email applies this, and a thing
 * out of scope is a 404 ("not found"), never a 403: the person must not learn that it exists.
 * Use {@see self::requireRecord()} for a single record. For a list, filter in the query on
 * {@see self::mailboxes()} (null means no filter) so a row limit counts only visible rows.
 */
final readonly class ApMailboxScope
{
    /** @param list<string>|null $mailboxes lower-case addresses; null is unrestricted */
    private function __construct(private ?array $mailboxes)
    {
    }

    public static function unrestricted(): self
    {
        return new self(null);
    }

    /**
     * An empty list is "no rows", which is unrestricted, as in the portal.
     *
     * @param list<string> $mailboxes
     */
    public static function limitedTo(array $mailboxes): self
    {
        $clean = [];
        foreach ($mailboxes as $mailbox) {
            $address = strtolower(trim($mailbox));
            if ($address !== '' && !in_array($address, $clean, true)) {
                $clean[] = $address;
            }
        }
        sort($clean);

        return new self($clean === [] ? null : $clean);
    }

    public function isRestricted(): bool
    {
        return $this->mailboxes !== null;
    }

    /**
     * The addresses a query may be limited to, or null for no limit.
     *
     * @return list<string>|null
     */
    public function mailboxes(): ?array
    {
        return $this->mailboxes;
    }

    /** Whether this mailbox (any case) is one the person may see. */
    public function allowsMailbox(string $mailbox): bool
    {
        return $this->mailboxes === null || in_array(strtolower(trim($mailbox)), $this->mailboxes, true);
    }

    /** `_invoice_visible`: a record with no mailbox (a hand capture) is visible to everyone. */
    public function allowsRecord(?string $mailbox): bool
    {
        $address = strtolower(trim((string) $mailbox));

        return $address === '' || $this->allowsMailbox($address);
    }

    /**
     * @throws ApNotFound when the record's mailbox is out of scope: "out of scope is a 404"
     */
    public function requireRecord(?string $mailbox, string $message = 'Not found'): void
    {
        if (!$this->allowsRecord($mailbox)) {
            throw new ApNotFound($message);
        }
    }
}
