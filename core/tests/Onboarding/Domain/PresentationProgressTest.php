<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Onboarding\Domain;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Onboarding\Domain\Exception\InvalidOnboardingValue;
use TriCab\Core\Onboarding\Domain\Exception\UnknownPresentationSlide;
use TriCab\Core\Onboarding\Domain\Service\PresentationProgressPolicy;
use TriCab\Core\Onboarding\Domain\ValueObject\PresentationManifest;

/**
 * Phase 3.3 — the rules behind "I have seen these slides".
 *
 * The two properties worth holding are that progress only ever grows, and that
 * completion is derived from the manifest rather than asserted by whoever is
 * posting. Everything below is one of those two.
 */
final class PresentationProgressTest extends TestCase
{
    // ------------------------------------------------------------ the manifest

    public function test_a_manifest_reads_both_authored_shapes(): void
    {
        $strings = PresentationManifest::fromJson('["intro","hazards","sign-off"]');
        $objects = PresentationManifest::fromJson(
            '[{"id":"intro","title":"Welcome"},{"id":"hazards"},{"id":"sign-off"}]',
        );

        self::assertSame(['intro', 'hazards', 'sign-off'], $strings->slideIds());
        self::assertSame(['intro', 'hazards', 'sign-off'], $objects->slideIds());
        self::assertSame('Welcome', $objects->titleFor('intro'));
        self::assertNull($objects->titleFor('hazards'), 'A slide may be untitled.');
        self::assertNull($strings->titleFor('intro'), 'The string form carries no titles.');
    }

    public function test_a_manifest_hands_each_slide_s_material_to_the_trainee(): void
    {
        $manifest = PresentationManifest::fromJson(json_encode([
            ['id' => 'intro', 'title' => 'Welcome', 'imageUrl' => 'https://example.test/1.webp',
                'media' => [['type' => 'video', 'url' => 'https://example.test/v.mp4', 'mimeType' => 'video/mp4']],
                'links' => [['text' => 'Policy', 'url' => 'https://example.test/p.pdf']]],
            ['id' => 'end', 'title' => 'The end'],
        ], JSON_THROW_ON_ERROR));

        self::assertSame([
            ['id' => 'intro', 'title' => 'Welcome', 'imageUrl' => 'https://example.test/1.webp',
                'media' => [['type' => 'video', 'url' => 'https://example.test/v.mp4', 'mimeType' => 'video/mp4']],
                'links' => [['text' => 'Policy', 'url' => 'https://example.test/p.pdf']]],
            ['id' => 'end', 'title' => 'The end'],
        ], $manifest->toArray());
        self::assertSame(['intro', 'end'], $manifest->slideIds(), 'Progress sees ids only.');

        $this->expectException(InvalidOnboardingValue::class);
        PresentationManifest::fromJson('[{"id":"intro","imageUrl":"javascript:alert(1)"}]');
    }

    /**
     * Strict where the trainee-facing reads are forgiving. A manifest is content
     * an author published and can fix; forgiving it would silently shorten a
     * presentation so that people finish without seeing the end.
     */
    public function test_a_malformed_manifest_is_refused_rather_than_shortened(): void
    {
        foreach ([
            'not JSON at all',
            '{"slides":["a"]}',
            '[{"title":"no id here"}]',
            '["  "]',
            '["intro","intro"]',
        ] as $malformed) {
            try {
                PresentationManifest::fromJson($malformed);
                self::fail('Accepted a malformed manifest: ' . $malformed);
            } catch (InvalidOnboardingValue) {
                self::assertTrue(true);
            }
        }
    }

    public function test_the_final_slide_is_the_last_one_in_the_manifest(): void
    {
        self::assertSame('sign-off', self::manifest()->finalSlideId());
        self::assertNull(PresentationManifest::fromArray([])->finalSlideId());
    }

    // -------------------------------------------------------------- the merge

    /**
     * The property the whole endpoint rests on. A second tab reporting slide 1
     * must not erase the fact that slide 3 was reached on a laptop.
     */
    public function test_progress_only_ever_grows(): void
    {
        $merged = PresentationProgressPolicy::merge(
            self::manifest(),
            ['intro', 'hazards', 'sign-off'],
            ['intro'],
        );

        self::assertSame(['intro', 'hazards', 'sign-off'], $merged);
    }

    public function test_slides_come_back_in_manifest_order_whatever_order_they_arrive_in(): void
    {
        $merged = PresentationProgressPolicy::merge(
            self::manifest(),
            ['sign-off'],
            ['hazards', 'intro', 'hazards'],
        );

        self::assertSame(['intro', 'hazards', 'sign-off'], $merged);
    }

    public function test_posting_the_same_slides_twice_produces_the_same_answer(): void
    {
        $once = PresentationProgressPolicy::merge(self::manifest(), [], ['intro', 'hazards']);
        $twice = PresentationProgressPolicy::merge(self::manifest(), $once, ['intro', 'hazards']);

        self::assertSame($once, $twice, 'The endpoint is idempotent without an idempotency key.');
    }

    /**
     * A fabricated slide id is an attempt to finish a module without opening it,
     * and silently dropping it would leave a person unable to explain why their
     * progress is short.
     */
    public function test_a_slide_the_manifest_does_not_have_is_refused_not_dropped(): void
    {
        $this->expectException(UnknownPresentationSlide::class);
        $this->expectExceptionMessageMatches('/invented-slide/');

        PresentationProgressPolicy::merge(self::manifest(), [], ['intro', 'invented-slide']);
    }

    /**
     * The other direction is deliberately tolerated: an author who removed a
     * slide after somebody saw it must not strand that person on a module they
     * can no longer make progress in.
     */
    public function test_a_stored_slide_the_author_has_since_removed_does_not_block_a_write(): void
    {
        $merged = PresentationProgressPolicy::merge(
            self::manifest(),
            ['intro', 'a-slide-that-was-deleted'],
            ['hazards'],
        );

        self::assertSame(['intro', 'hazards'], $merged);
    }

    // ----------------------------------------------------------- completion

    public function test_a_presentation_is_finished_only_when_the_last_slide_is_reached(): void
    {
        $manifest = self::manifest();

        self::assertFalse(PresentationProgressPolicy::isPresentationComplete($manifest, ['intro', 'hazards'], 0));
        self::assertTrue(PresentationProgressPolicy::isPresentationComplete(
            $manifest,
            ['intro', 'hazards', 'sign-off'],
            0,
        ));
    }

    /**
     * A zero requirement means "the whole thing", not "no slides needed". The
     * other reading would mark every module complete the moment it was opened,
     * and zero is the column default.
     */
    public function test_a_required_count_of_zero_means_the_whole_deck(): void
    {
        self::assertFalse(PresentationProgressPolicy::isPresentationComplete(self::manifest(), [], 0));
    }

    public function test_a_required_count_is_a_floor_on_top_of_the_final_slide(): void
    {
        $manifest = PresentationManifest::fromArray(['a', 'b', 'c', 'appendix']);

        // Reached the end by skipping the middle: the floor of three is not met.
        self::assertFalse(PresentationProgressPolicy::isPresentationComplete($manifest, ['a', 'appendix'], 3));
        self::assertTrue(PresentationProgressPolicy::isPresentationComplete($manifest, ['a', 'b', 'appendix'], 3));
    }

    public function test_an_empty_manifest_is_never_complete(): void
    {
        $empty = PresentationManifest::fromArray([]);

        self::assertFalse(PresentationProgressPolicy::hasReachedFinalSlide($empty, []));
        self::assertFalse(PresentationProgressPolicy::isPresentationComplete($empty, [], 0));
        self::assertSame(0, PresentationProgressPolicy::percent($empty, []));
    }

    public function test_the_percentage_is_for_display_and_floors_rather_than_rounds(): void
    {
        $manifest = PresentationManifest::fromArray(['a', 'b', 'c']);

        self::assertSame(0, PresentationProgressPolicy::percent($manifest, []));
        self::assertSame(33, PresentationProgressPolicy::percent($manifest, ['a']));
        self::assertSame(66, PresentationProgressPolicy::percent($manifest, ['a', 'b']));
        self::assertSame(100, PresentationProgressPolicy::percent($manifest, ['a', 'b', 'c']));
    }

    private static function manifest(): PresentationManifest
    {
        return PresentationManifest::fromArray(['intro', 'hazards', 'sign-off']);
    }
}
