<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Domain\Service;

use TriCab\Core\Onboarding\Domain\ValueObject\PresentationManifest;

/**
 * What it means to have got somewhere in a presentation.
 *
 * ## Progress only ever grows
 *
 * {@see merge()} unions what is stored with what has just arrived. It never
 * removes a slide, and that is deliberate rather than convenient: a client that
 * posts its current position — a second tab, a phone picked up after a laptop,
 * a retry that lost its earlier batch — would otherwise erase progress by
 * reporting it honestly. A union makes every one of those cases harmless, and
 * makes the endpoint idempotent without an idempotency key: posting the same
 * slides twice produces the same row.
 *
 * The cost is that progress cannot be reset by a client. It should not be.
 * Resetting is what supersession does, from the author's side, with the old
 * evidence left on the old version.
 *
 * ## Completion is derived, never asserted
 *
 * A client says which slides it has shown. It does not say whether the
 * presentation is finished — {@see hasReachedFinalSlide()} and
 * {@see isPresentationComplete()} decide that from the manifest. Work order
 * invariant 7 is usually read as being about quizzes; it applies here too. A
 * `complete: true` field in a request body is a client marking its own homework.
 *
 * ## RequiredSlideCount, and why it is a floor rather than the rule
 *
 * `TrainingModuleVersions.RequiredSlideCount` lets an author say "at least this
 * many", for a deck with optional appendices. It defaults to 0, and 0 means "the
 * whole thing": a zero requirement that meant "no slides needed" would mark
 * every module complete the moment it was opened, which is the wrong way round
 * for a default. Both the final slide and the floor must be satisfied.
 *
 * Pure: no database, no request, no clock.
 */
final class PresentationProgressPolicy
{
    /**
     * Folds newly reported slides into what is already recorded.
     *
     * @param list<string> $alreadyReached what the row holds
     * @param list<string> $newlyReached   what the client just reported
     *
     * @return list<string> in manifest order, deduplicated
     *
     * @throws \TriCab\Core\Onboarding\Domain\Exception\UnknownPresentationSlide
     */
    public static function merge(
        PresentationManifest $manifest,
        array $alreadyReached,
        array $newlyReached,
    ): array {
        // Only the incoming slides are validated. A stored id that is no longer
        // in the manifest is history — the author removed a slide after somebody
        // saw it — and refusing the write would strand that person on a module
        // they can no longer make progress in.
        $manifest->assertContainsAll($newlyReached);

        return $manifest->inManifestOrder(array_merge($alreadyReached, $newlyReached));
    }

    /**
     * @param list<string> $reached
     */
    public static function hasReachedFinalSlide(PresentationManifest $manifest, array $reached): bool
    {
        $finalSlideId = $manifest->finalSlideId();

        // An empty manifest has no end to reach. Saying "finished" would mark a
        // module complete that has no content in it, which is an authoring
        // mistake this class must not paper over.
        if ($finalSlideId === null) {
            return false;
        }

        return in_array($finalSlideId, $reached, true);
    }

    /**
     * @param list<string> $reached
     */
    public static function isPresentationComplete(
        PresentationManifest $manifest,
        array $reached,
        int $requiredSlideCount,
    ): bool {
        if (!self::hasReachedFinalSlide($manifest, $reached)) {
            return false;
        }

        $floor = $requiredSlideCount > 0 ? $requiredSlideCount : $manifest->slideCount();

        return count($reached) >= $floor;
    }

    /**
     * How far through, as a percentage, for display only.
     *
     * Nobody is assessed on this and nothing branches on it — the completion
     * decision is {@see isPresentationComplete()}, which is about specific
     * slides rather than a count. Kept as integer arithmetic for the reason
     * {@see \TriCab\Core\Onboarding\Domain\ValueObject\AssignmentStatus} keeps
     * its weights that way: a figure computed in floats invites somebody to
     * treat it as a measurement.
     *
     * @param list<string> $reached
     */
    public static function percent(PresentationManifest $manifest, array $reached): int
    {
        $total = $manifest->slideCount();

        if ($total === 0) {
            return 0;
        }

        return (int) floor(count($reached) * 100 / $total);
    }
}
