<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Rfq\Persistence;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Rfq\Persistence\AzureSqlRfqCorrectionMemory;
use TriCab\Api\Tests\Doubles\FakeSqlConnection;
use TriCab\Core\Tests\Identity\Doubles\FixedClock;

/**
 * Parity plan E5.5: the correction memory as the source's `correction_for`
 * reads it, and Save Review as `save_review` and
 * `learn_approved_corrections` write it.
 */
final class AzureSqlRfqCorrectionMemoryTest extends TestCase
{
    private static function memory(FakeSqlConnection $connection): AzureSqlRfqCorrectionMemory
    {
        $n = 0;

        return new AzureSqlRfqCorrectionMemory($connection, FixedClock::utc('2026-10-02T01:00:00'), static function () use (&$n): string {
            return str_pad((string) ++$n, 32, '0', STR_PAD_LEFT);
        });
    }

    public function test_each_key_resolves_to_its_most_confirmed_code_and_a_tie_to_nothing(): void
    {
        $connection = new FakeSqlConnection();
        $connection->queueFetch([
            ['normalized_text' => '4c 16mm2', 'product_code' => 'A', 'confirmations' => 3],
            ['normalized_text' => '4c 16mm2', 'product_code' => 'B', 'confirmations' => 1],
            ['normalized_text' => 'tps 2.5', 'product_code' => 'C', 'confirmations' => 2],
            ['normalized_text' => 'tps 2.5', 'product_code' => 'D', 'confirmations' => 2],
            ['normalized_text' => 'tps 2.5', 'product_code' => 'E', 'confirmations' => 1],
        ]);

        self::assertSame(['4c 16mm2' => ['product_code' => 'A', 'confirmations' => 3]], self::memory($connection)->resolved());
    }

    public function test_a_review_is_a_session_its_entries_and_a_confirmation_per_learned_key_in_one_transaction(): void
    {
        $connection = new FakeSqlConnection();
        $rows = [
            ['raw_text' => '4C 16mm2', 'final_status' => 'approved', 'final_product_code' => 'A', 'reviewer_note' => 'ok',
                'source' => ['file_name' => 'b.xlsx', 'row_number' => 4, 'original_text' => '4C 16mm2 x 100m']],
            ['raw_text' => 'lighting', 'status' => 'not_cable', 'source' => ['file_name' => 'a.pdf']],
        ];
        $system = [['raw_text' => '4C 16mm2', 'status' => 'matched', 'selected_product_code' => 'B', 'confidence' => 0.8], []];

        $session = self::memory($connection)->recordReview('d' . str_repeat('0', 31), 3, [], $rows, $system, 'rep@tricab.com', 'VIC', [
            ['key' => '4c 16mm2', 'productCode' => 'A'],
        ]);

        self::assertTrue($connection->committed);
        self::assertSame(str_pad('1', 32, '0', STR_PAD_LEFT), $session);
        self::assertCount(3, $connection->calls);
        [$sessionRow, $entries, $learn] = $connection->calls;
        self::assertStringContainsString('[rfq].[rfq_sessions]', $sessionRow['sql']);
        self::assertSame('["a.pdf", "b.xlsx"]', $sessionRow['params']['file_names']);
        self::assertSame('{}', $sessionRow['params']['quote_details']);
        self::assertSame([2, 3, 'VIC'], [$sessionRow['params']['line_count'], $sessionRow['params']['draft_revision'], $sessionRow['params']['actor_branch']]);

        self::assertStringContainsString('[rfq].[rfq_entries]', $entries['sql']);
        self::assertSame(['B', 'matched', 'A', 'approved', 'ok', 4], [
            $entries['params']['system_product_code_0'],
            $entries['params']['system_status_0'],
            $entries['params']['final_product_code_0'],
            $entries['params']['final_status_0'],
            $entries['params']['reviewer_note_0'],
            $entries['params']['source_row_number_0'],
        ]);
        self::assertSame(['not_cable', 'not_cable'], [$entries['params']['system_status_1'], $entries['params']['final_status_1']], 'no system row: the row speaks for itself');

        self::assertStringContainsString('[confirmations] + 1', $learn['sql']);
        self::assertSame(['4c 16mm2', 'A'], [$learn['params']['normalized_text'], $learn['params']['product_code']]);
    }
}
