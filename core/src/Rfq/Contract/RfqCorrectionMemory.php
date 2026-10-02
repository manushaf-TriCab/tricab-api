<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Contract;

use TriCab\Core\Rfq\Domain\Exception\RfqStoreUnavailable;

/**
 * Correction memory and the review log (parity plan E5.5; parent plan 4.4):
 * the source's `rfq_correction_memory`, `rfq_sessions` and `rfq_entries`,
 * with `correction_for`, `save_review` and `learn_approved_corrections`.
 *
 * The parser never opens the table. Every parse is sent the resolved memory
 * ({@see resolved()}), and Save Review asks the parser only for each approved
 * line's key; this side writes.
 */
interface RfqCorrectionMemory
{
    /**
     * Every key with the code it resolves to. As `correction_for` decides it:
     * the code with the most confirmations, latest first, and no answer at all
     * for a key whose top two codes tie.
     *
     * @return array<string, array{product_code: string, confirmations: int}>
     *
     * @throws RfqStoreUnavailable
     */
    public function resolved(): array;

    /**
     * One review: a session row, an entry per line (what the parser said
     * beside what was decided), and one more confirmation for each learned
     * key and code. One transaction.
     *
     * @param array<string, mixed>                          $quoteDetails
     * @param list<array<string, mixed>>                    $rows       the lines as saved
     * @param list<array<string, mixed>>                    $systemRows the parser's answer per line, `[]` where there is none
     * @param list<array{key: string, productCode: string}> $learned
     *
     * @return string the session id
     *
     * @throws RfqStoreUnavailable
     */
    public function recordReview(
        string $draftId,
        int $revision,
        array $quoteDetails,
        array $rows,
        array $systemRows,
        string $actorEmail,
        ?string $actorBranch,
        array $learned,
    ): string;
}
