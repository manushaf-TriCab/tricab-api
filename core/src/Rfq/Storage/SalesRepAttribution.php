<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Storage;

/**
 * The sales rep on an RFQ is whoever worked on it last (owner decision, 28 Sep
 * 2026). "Worked on" is saving its content or sending it to 4js; opening,
 * commenting, workflow moves and archiving do not count.
 *
 * Both of the source's "rep" fields follow the worker:
 *
 *  - **the assignee** (`assignee_email`), whom the queue shows against it;
 *  - **the 4js author** (`quote_details.author`), the operator code the quote
 *    is raised under in 4js.
 *
 * The author takes the worker's operator code when the operator table
 * confirms one. When it does not, an existing author is **kept**, because a
 * guess must not overwrite a real code; and a blank author gets the source's
 * own fallback, the upper-cased email login (`quote_author.author_for_login`),
 * which 4js rejects loudly if it is wrong. `author_source` says which.
 *
 * Pure. The draft store applies it inside the save's transaction; the Phase 5
 * sync applies it when an attempt is created.
 */
final class SalesRepAttribution
{
    public const AUTHOR_FROM_OPERATOR = 'operator';
    public const AUTHOR_FROM_LOGIN = 'login';
    public const AUTHOR_KEPT = 'kept';

    /**
     * @param array<string, mixed> $quoteDetails the header as it will be saved
     *
     * @return array{quoteDetails: array<string, mixed>, assignee: ?string, authorSource: ?string, assigneeChange: ?array{from: ?string, to: string}}
     */
    public static function apply(array $quoteDetails, ?string $currentAssignee, DraftActor $worker, ?string $operatorCode): array
    {
        $current = $currentAssignee === null ? '' : StoreFormat::email($currentAssignee);
        if ($worker->email === null) {
            // Nobody to attribute to (a system write): leave both as they are.
            return ['quoteDetails' => $quoteDetails, 'assignee' => $current !== '' ? $current : null, 'authorSource' => null, 'assigneeChange' => null];
        }

        $existing = PythonText::strip(PythonText::str(DraftChangeLog::truthy($quoteDetails['author'] ?? null) ? $quoteDetails['author'] : ''));
        $code = $operatorCode === null ? '' : PythonText::upper(PythonText::strip($operatorCode));
        if ($code !== '') {
            $quoteDetails['author'] = $code;
            $source = self::AUTHOR_FROM_OPERATOR;
        } elseif ($existing !== '') {
            $source = self::AUTHOR_KEPT;
        } else {
            $login = self::login($worker->email);
            if ($login !== '') {
                $quoteDetails['author'] = PythonText::upper($login);
            }
            $source = $login !== '' ? self::AUTHOR_FROM_LOGIN : null;
        }

        return [
            'quoteDetails' => $quoteDetails,
            'assignee' => $worker->email,
            'authorSource' => $source,
            'assigneeChange' => $current === $worker->email ? null : ['from' => $current !== '' ? $current : null, 'to' => $worker->email],
        ];
    }

    /** The source's `login_from_email`: a TriCab address's local part, else nothing. */
    public static function login(string $email): string
    {
        $email = StoreFormat::email($email);

        return str_contains($email, '@tricab.com') ? explode('@', $email, 2)[0] : '';
    }
}
