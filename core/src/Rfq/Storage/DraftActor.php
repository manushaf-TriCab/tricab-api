<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Storage;

/**
 * Who a draft write is attributed to, in the store's own terms: the columns
 * `rfq_draft_activity` and `rfq_draft_row_edits` carry (`actor_email`,
 * `actor_role`, `actor_branch`).
 *
 * The store is keyed by email, as the source was. The service builds this
 * from the resolved platform actor; nothing here comes from a request body.
 * `operatorCode` is the person's confirmed 4js operator code
 * (`RfqOperatorDirectory`), which a save puts on the quote as its author
 * ({@see SalesRepAttribution}).
 */
final class DraftActor
{
    public readonly ?string $email;

    public readonly ?string $branch;

    public function __construct(
        ?string $email,
        public readonly ?string $role,
        ?string $branch,
        public readonly ?string $operatorCode = null,
    ) {
        $email = $email === null ? '' : mb_strtolower(PythonText::strip($email), 'UTF-8');
        $branch = $branch === null ? '' : PythonText::upper(PythonText::strip($branch));
        $this->email = $email !== '' ? $email : null;
        $this->branch = $branch !== '' ? $branch : null;
    }
}
