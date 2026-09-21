<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Domain\Service;

use TriCab\Core\Onboarding\Domain\Exception\FormVersionNotClassified;

/**
 * A payroll or restricted-HR form cannot be published until every field in it
 * has been classified.
 *
 * ## The gap this closes
 *
 * Classification is per field, because `employee-details` arrives as one payload
 * mixing ordinary, payroll and restricted data and so cannot be classified as a
 * whole. The split runs on the way **in**, driven by
 * `onboarding_hr.FieldClassifications`.
 *
 * The obvious design — "a field nobody classified is ordinary" — fails **open**.
 * A sensitive field added to a form and never classified is written to
 * `[onboarding]` in clear, where reports, the reviewer queue and every ordinary
 * query can read it, and nothing can tell "nobody looked at this" apart from
 * "somebody decided it was fine".
 *
 * So C2 is a recordable answer, and this guard makes it a required one. On a
 * form whose access group is `payroll` or `restricted_hr`, publishing is refused
 * until every field in the schema has a row. Unclassified then means
 * unpublishable rather than silently ordinary.
 *
 * ## What it does not cover, said plainly
 *
 * This is application code. The rule spans a classification table, a form
 * version's JSON schema and a publish action, and no CHECK constraint can
 * express it. A write that goes round the publish service goes round this
 * guard. That is the residual risk in the classification design, and it is
 * stated here rather than left for somebody to find.
 *
 * An `hr` form is not guarded, because by definition none of its fields is
 * payroll or restricted. Changing a form's access group to `payroll` therefore
 * makes its existing fields unclassified, and the next publish refuses — which
 * is the intended behaviour and the reason the check is per version rather than
 * per definition.
 *
 * Pure: no database, no request, no clock.
 */
final class FormVersionPublishGuard
{
    public const ACCESS_GROUP_HR = 'hr';
    public const ACCESS_GROUP_PAYROLL = 'payroll';
    public const ACCESS_GROUP_RESTRICTED_HR = 'restricted_hr';

    /** The access groups whose fields must all be classified before publishing. */
    private const REQUIRES_CLASSIFICATION = [
        self::ACCESS_GROUP_PAYROLL,
        self::ACCESS_GROUP_RESTRICTED_HR,
    ];

    public static function requiresClassification(string $accessGroup): bool
    {
        return in_array($accessGroup, self::REQUIRES_CLASSIFICATION, true);
    }

    /**
     * Fields in the schema with no classification recorded, in schema order.
     *
     * Order matters: an HR user fixing this works down the form, and an
     * alphabetised list of twelve field paths makes them hunt.
     *
     * @param list<string> $schemaFieldPaths every field in the published schema
     * @param list<string> $classifiedFieldPaths every field with a row in FieldClassifications
     *
     * @return list<string>
     */
    public static function unclassifiedFields(array $schemaFieldPaths, array $classifiedFieldPaths): array
    {
        $classified = array_flip($classifiedFieldPaths);

        return array_values(array_filter(
            $schemaFieldPaths,
            static fn (string $path): bool => !isset($classified[$path]),
        ));
    }

    /**
     * @param list<string> $schemaFieldPaths
     * @param list<string> $classifiedFieldPaths
     *
     * @throws FormVersionNotClassified
     */
    public static function assertPublishable(
        string $accessGroup,
        array $schemaFieldPaths,
        array $classifiedFieldPaths,
    ): void {
        if (!self::requiresClassification($accessGroup)) {
            return;
        }

        $missing = self::unclassifiedFields($schemaFieldPaths, $classifiedFieldPaths);

        if ($missing !== []) {
            throw FormVersionNotClassified::forFields($missing);
        }
    }
}
