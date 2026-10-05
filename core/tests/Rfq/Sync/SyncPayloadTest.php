<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Rfq\Sync;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Rfq\Sync\SyncPayload;

/**
 * The frozen plan's text is the source's `json.dumps(plan, sort_keys=True,
 * separators=(",", ":"), ensure_ascii=False)`. The expected text below was
 * produced by Python 3 for the same plan.
 */
final class SyncPayloadTest extends TestCase
{
    public function test_it_is_the_sources_compact_sorted_text(): void
    {
        $plan = [
            'b' => [1, 2.5, 100.0, 1e-07, 1e22, 13.334, true, null, "é\u{2028}/\"\\\n\t\x01"],
            'a' => ['z' => 'ü', 'y' => []],
            'A' => 'upper',
            'é' => 1,
            '10' => 'ten',
            '9' => 'nine',
        ];

        self::assertSame(
            '{"10":"ten","9":"nine","A":"upper","a":{"y":[],"z":"ü"},"b":[1,2.5,100.0,1e-07,1e+22,13.334,true,null,"é' . "\u{2028}" . '/\"\\\\\n\t\u0001"],"é":1}',
            SyncPayload::encode($plan),
        );
    }

    public function test_the_hash_is_the_sha256_of_those_bytes(): void
    {
        $text = SyncPayload::encode(['a' => 'é']);

        self::assertSame(hash('sha256', '{"a":"é"}'), SyncPayload::sha256($text));
        self::assertSame(64, strlen(SyncPayload::sha256($text)));
    }

    public function test_the_same_plan_always_gives_the_same_hash_whatever_order_it_was_built_in(): void
    {
        $one = SyncPayload::encode(['hdr_params' => ['ref' => '0', 'cust' => '1'], 'line_items' => [['code' => 'A', 'price' => 5.5]]]);
        $two = SyncPayload::encode(['line_items' => [['price' => 5.5, 'code' => 'A']], 'hdr_params' => ['cust' => '1', 'ref' => '0']]);

        self::assertSame($one, $two);
        self::assertSame(SyncPayload::sha256($one), SyncPayload::sha256($two));
    }

    public function test_a_value_json_cannot_carry_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SyncPayload::encode(['a' => new \stdClass()]);
    }
}
