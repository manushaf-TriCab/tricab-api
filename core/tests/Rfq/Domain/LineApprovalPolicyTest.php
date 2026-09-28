<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Rfq\Domain;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Rfq\Domain\Exception\RfqTransitionNotPermitted;
use TriCab\Core\Rfq\Domain\Service\LineApprovalPolicy;
use TriCab\Core\Rfq\Domain\ValueObject\LineApproval;
use TriCab\Core\Rfq\Domain\ValueObject\LineDisposition;
use TriCab\Core\Rfq\Domain\ValueObject\LineInputType;
use TriCab\Core\Rfq\Domain\ValueObject\LineMatchStatus;

/**
 * Plan 1.2 — row approval, kept apart from the parser's match status, and the
 * line-level sync gate from `quote_readiness.row_push_block_reason`.
 */
final class LineApprovalPolicyTest extends TestCase
{
    private static function block(
        string $match,
        string $approval = LineApproval::UNREVIEWED,
        string $disposition = LineDisposition::INCLUDED,
        string $input = LineInputType::DETERMINISTIC,
        bool $hasCode = true,
        bool $reviewedInEditor = true,
    ): ?string {
        return LineApprovalPolicy::syncBlockReason(
            LineMatchStatus::fromString($match),
            LineApproval::fromString($approval),
            LineDisposition::fromString($disposition),
            LineInputType::fromString($input),
            $hasCode,
            $reviewedInEditor,
        );
    }

    /** Invariant 2: model output never approves itself. */
    public function test_only_a_person_approves(): void
    {
        LineApprovalPolicy::assertMaySet(LineApproval::approved(), byPerson: true);
        LineApprovalPolicy::assertMaySet(LineApproval::unreviewed(), byPerson: false);

        $this->expectException(RfqTransitionNotPermitted::class);
        LineApprovalPolicy::assertMaySet(LineApproval::approved(), byPerson: false);
    }

    public function test_a_matched_line_does_not_block(): void
    {
        self::assertNull(self::block(LineMatchStatus::MATCHED));
    }

    public function test_an_undecided_line_blocks_until_approved(): void
    {
        foreach (['needs_review', 'ambiguous', 'unresolved', 'no_match', 'accessory'] as $status) {
            self::assertNotNull(self::block($status), $status);
            self::assertNull(self::block($status, LineApproval::APPROVED), $status);
        }
    }

    /**
     * Owner question Q1. Production has run `needs_clarification` as advisory
     * since 25 Sept 2026. If production is reverted, this assertion flips and
     * `LineMatchStatus::BLOCKING` gains the value.
     */
    public function test_needs_clarification_is_advisory_as_production_runs_it(): void
    {
        self::assertNull(self::block(LineMatchStatus::NEEDS_CLARIFICATION));
    }

    /** Source `exclusion_cannot_be_overridden_by_approval`, from the other side: excluded never blocks. */
    public function test_an_excluded_line_never_blocks(): void
    {
        self::assertNull(self::block(LineMatchStatus::NO_MATCH, disposition: LineDisposition::EXCLUDED));
    }

    public function test_an_uncoded_heading_is_excluded_but_a_coded_one_is_not(): void
    {
        $reason = LineApprovalPolicy::exclusionReason(
            LineDisposition::included(),
            LineMatchStatus::fromString(LineMatchStatus::ADMIN),
            hasProductCode: false,
        );
        self::assertSame('Non-commercial source content', $reason);

        self::assertNull(LineApprovalPolicy::exclusionReason(
            LineDisposition::included(),
            LineMatchStatus::fromString(LineMatchStatus::ADMIN),
            hasProductCode: true,
        ));
    }

    public function test_an_ungrounded_line_needs_approval_off_the_editor_path(): void
    {
        self::assertNotNull(self::block(LineMatchStatus::MATCHED, input: LineInputType::VISION_OCR, reviewedInEditor: false));
        self::assertNotNull(self::block(LineMatchStatus::MATCHED, input: LineInputType::READER_UNGROUNDED, reviewedInEditor: false));
        self::assertNull(self::block(
            LineMatchStatus::MATCHED,
            LineApproval::APPROVED,
            input: LineInputType::VISION_OCR,
            reviewedInEditor: false,
        ));
    }

    /**
     * Preserved as found: the source's draft sync passes `reviewed=True`, so a
     * scan-read line saved through the editor syncs without an approval.
     * Flagged for owner review in the policy docblock.
     */
    public function test_a_save_through_the_editor_counts_as_review_for_ungrounded_lines(): void
    {
        self::assertNull(self::block(LineMatchStatus::MATCHED, input: LineInputType::VISION_OCR, reviewedInEditor: true));
    }

    /** The source leaves input_type empty or absent for the deterministic parser. */
    public function test_a_missing_source_input_type_is_the_deterministic_parser(): void
    {
        self::assertSame(LineInputType::DETERMINISTIC, LineInputType::fromSource(null)->toString());
        self::assertSame(LineInputType::DETERMINISTIC, LineInputType::fromSource('')->toString());
        self::assertSame(LineInputType::VISION_OCR, LineInputType::fromSource('vision_ocr')->toString());
        // A platform row with no origin is a defect, and a defect is not grounded.
        self::assertTrue(LineInputType::fromStorage(null)->isUngrounded());
    }
}
