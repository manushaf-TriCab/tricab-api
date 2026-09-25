<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Domain\Exception;

use DomainException;

/**
 * Work arriving against a version of the content that is no longer the one this
 * person is allocated.
 *
 * The case is ordinary rather than exotic: somebody leaves a presentation open
 * over lunch, HR publishes a substantive rewrite with `SupersedesWorkInProgress`
 * set, and the tab posts its next slide against wording that has been replaced.
 *
 * Accepting it is the thing that must not happen. An acknowledgement or an
 * assessment is evidence about *specific wording*, and the version id is how the
 * evidence says which. Recording progress against the old version under the new
 * allocation would produce a record claiming somebody read something they did
 * not.
 *
 * So the write is refused and the client is told to reload. It loses at most the
 * slides since the last save, which is the cheap half of the trade.
 *
 * Maps to 409, with a code the SPA acts on by refetching rather than by showing
 * an error.
 */
final class ContentVersionSuperseded extends DomainException
{
    public static function reload(): self
    {
        return new self(
            'This item has been updated since you opened it. Reload to continue on the current version.',
        );
    }
}
