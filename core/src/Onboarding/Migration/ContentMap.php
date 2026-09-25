<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Migration;

/**
 * Which platform item a source content row is (Phase 7.3).
 *
 *  - **Modules** have no slug in the source; `modules.document_reference` is
 *    their natural key. The three TriCab modules map as the 5.4 training import
 *    created them (`SourceTrainingConverter::MODULES`, restated here so core
 *    does not depend on the import).
 *  - **Policies and forms** carry the source's own slug, which the handbook
 *    and form imports kept.
 *
 * A synthetic snapshot may also name a module `SYNTHETIC:<platform slug>`,
 * so the rehearsal can use the `demo-` modules. A real snapshot cannot: that
 * prefix is refused outside a synthetic one.
 */
final class ContentMap
{
    public const SYNTHETIC_PREFIX = 'SYNTHETIC:';

    /** document reference => platform module slug */
    public const MODULES = [
        'HR-DOC-009-V010' => 'corporate-whse-induction',
        'HR-DOC-098-V000' => 'respect-at-work',
        'HR-DOC-094-V000' => 'workplace-sexual-harassment',
    ];

    public static function moduleSlug(string $documentReference, bool $synthetic): ?string
    {
        $reference = trim($documentReference);
        if (isset(self::MODULES[$reference])) {
            return self::MODULES[$reference];
        }
        if ($synthetic && str_starts_with($reference, self::SYNTHETIC_PREFIX)) {
            $slug = substr($reference, strlen(self::SYNTHETIC_PREFIX));

            return preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug) === 1 ? $slug : null;
        }

        return null;
    }
}
