<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Contract;

/**
 * A staff member's 4js operator code (`descript` desc_type 36, e.g. `SCOTTH`):
 * what an RFQ's quote header carries as `author`, and what 4js checks on the
 * push. The source's `find_operator_by_login`.
 *
 * Answers only what the operator table confirms. A guess from the email's
 * local part is {@see \TriCab\Core\Rfq\Storage\SalesRepAttribution}'s to make,
 * and only where there is no author at all.
 */
interface RfqOperatorDirectory
{
    /** The upper-cased operator code, or null when none is confirmed. */
    public function codeFor(string $email): ?string;
}
