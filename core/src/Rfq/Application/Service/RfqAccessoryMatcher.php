<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Application\Service;

use DateTimeImmutable;
use TriCab\Core\Rfq\Application\DTO\AccessoryMatch;
use TriCab\Core\Rfq\Contract\RfqAccessoryEngine;
use TriCab\Core\Rfq\Domain\Exception\InvalidRfqValue;
use TriCab\Core\Rfq\Domain\Exception\RfqAccessoryEngineFailure;
use TriCab\Core\Rfq\Parser\AccessorySeal;
use TriCab\Core\Rfq\Storage\DraftChangeLog;
use TriCab\Core\Rfq\Storage\DraftLineEdits;
use TriCab\Core\Rfq\Storage\PythonText;
use TriCab\Core\Rfq\Storage\StoreFormat;

/**
 * Match Accessories (parity plan E7.2): which cable lines to ask about, what the
 * engine proposes for each, and the accessory row each proposal becomes.
 *
 * Ported from the source's `rfq_handle_accessories_match`,
 * `rfq_product_to_accessory_row` and `rfq_insert_accessory_rows`
 * (`rfq_api.php`), and the editor's `isCableRow` / `isAccessoryAlreadyInQuote`
 * (`static/app.js`). The engine is a port ({@see RfqAccessoryEngine}); prices
 * are not the engine's but the pricing pass's, so a candidate shows the price
 * the line would have once added.
 */
final class RfqAccessoryMatcher
{
    public function __construct(
        private readonly RfqAccessoryEngine $engine,
        private readonly ?AccessorySeal $seal,
    ) {
    }

    public function engine(): RfqAccessoryEngine
    {
        return $this->engine;
    }

    /**
     * The editor's `isCableRow`: not an accessory, not a settled non-cable or an
     * unresolved line, and with a product.
     *
     * @param array<string, mixed> $row
     */
    public static function isCableRow(array $row): bool
    {
        if (strtolower(PythonText::str($row['line_kind'] ?? '')) === 'accessory') {
            return false;
        }
        $status = strtolower(PythonText::str(($row['final_status'] ?? null) ?: ($row['status'] ?? '')));
        if (in_array($status, ['accessory', 'not_cable', 'unresolved'], true)) {
            return false;
        }

        return PythonText::strip(DraftLineEdits::productCode($row)) !== '';
    }

    /**
     * The proposals for the cable lines asked about, as accessory rows (no
     * price yet, no `line_uid`), grouped under the line they are for.
     *
     * @param list<array<string, mixed>> $rows        every line on the quote, in order
     * @param list<string>|null          $parentUids  the lines to ask about; null for every cable line
     *
     * @return array{parents: list<array{lineUid: string, productCode: string, rows: list<array<string, mixed>>}>, skipped: list<array{lineUid: string, code: string, reason: string}>}
     *
     * @throws InvalidRfqValue when a named line is not on the quote
     */
    public function propose(array $rows, ?array $parentUids, int $customerId, string $region, string $currency): array
    {
        $known = [];
        foreach ($rows as $row) {
            $known[PythonText::str($row['line_uid'] ?? '')] = true;
        }
        foreach ($parentUids ?? [] as $uid) {
            if (!isset($known[$uid])) {
                throw new InvalidRfqValue(sprintf('Line %s is not on this quote. Reload it.', $uid));
            }
        }

        // The editor drops what is already on the quote under the same parent.
        $existing = [];
        foreach ($rows as $row) {
            if (strtolower(PythonText::str($row['line_kind'] ?? '')) === 'accessory') {
                $existing[self::key($row)] = true;
            }
        }

        $parents = [];
        $skipped = [];
        foreach ($rows as $row) {
            $uid = PythonText::str($row['line_uid'] ?? '');
            if (!self::isCableRow($row) || ($parentUids !== null && !in_array($uid, $parentUids, true))) {
                continue;
            }
            $code = PythonText::strip(DraftLineEdits::productCode($row));
            $length = DraftChangeLog::object($row['length'] ?? null);
            try {
                $matches = $this->engine->match($code, self::totalLength($length), max(1, (int) ($length['number_of_lengths'] ?? 1)), $customerId, $region, $currency);
            } catch (RfqAccessoryEngineFailure $failure) {
                $skipped[] = ['lineUid' => $uid, 'code' => $code, 'reason' => $failure->getMessage()];
                continue;
            }
            $built = [];
            foreach ($matches as $match) {
                $matchCode = trim($match->productCode);
                if ($matchCode === '' || strtoupper($matchCode) === strtoupper($code) || $match->isCable) {
                    continue;
                }
                $accessory = self::accessoryRow($match, $code, $region);
                $key = self::key($accessory);
                if (isset($existing[$key])) {
                    continue;
                }
                $existing[$key] = true;
                $built[] = $accessory;
            }
            $parents[] = ['lineUid' => $uid, 'productCode' => $code, 'rows' => $built];
        }

        return ['parents' => $parents, 'skipped' => $skipped];
    }

    /**
     * The source's `rfq_product_to_accessory_row`. Accessories are sold in whole
     * packs, so the requested units round up to a pack multiple, at least one
     * pack. Price and cost are left for the pricing pass.
     *
     * @return array<string, mixed>
     */
    public static function accessoryRow(AccessoryMatch $match, string $parentCode, string $region): array
    {
        $code = trim($match->productCode);
        $perPack = $match->unitsPerPack < 1 ? 1.0 : $match->unitsPerPack;
        $quantity = $match->noUnits > 0 ? $match->noUnits : ($match->noPacks > 0 ? $match->noPacks * $perPack : $perPack);
        $quantity = ceil($quantity / $perPack) * $perPack;
        $units = self::whole($quantity);

        return [
            'line_kind' => 'accessory',
            'parent_product_code' => $parentCode,
            'raw_text' => 'Accessory for ' . $parentCode,
            'source' => ['original_text' => 'Accessory for ' . $parentCode, 'input_type' => 'manual_entry'],
            'line_note' => 'Accessory for ' . $parentCode,
            'description' => mb_substr(trim($match->description), 0, 300),
            'selected_product_code' => $code,
            'final_product_code' => $code,
            'status' => 'matched',
            'final_status' => 'matched',
            'confidence' => 1,
            'length' => ['number_of_lengths' => $units, 'length_each' => 1, 'total_length' => $units, 'unit' => 'ea'],
            'quote_quantity' => $units,
            'quote_unit' => 'unit',
            'units_per_pack' => self::whole($perPack),
            'unit_price' => '',
            'final_unit_price' => '',
            'line_total' => '',
            'pricing' => ['status' => 'pending', 'source' => 'tricab_cost_api', 'region' => $region],
        ];
    }

    /** `rfq_row_total_length`: the first positive length the line states, else 1 metre. */
    private static function totalLength(array $length): float
    {
        foreach (['total_length', 'total_length_m', 'length_each', 'length_each_m'] as $key) {
            if (isset($length[$key]) && is_numeric($length[$key]) && (float) $length[$key] > 0) {
                return (float) $length[$key];
            }
        }

        return 1.0;
    }

    /** `accessoryKey`: the parent's code and the accessory's, upper-cased. */
    private static function key(array $row): string
    {
        return strtoupper(trim(PythonText::str($row['parent_product_code'] ?? ''))) . '|' . strtoupper(trim(PythonText::str(($row['final_product_code'] ?? null) ?: ($row['selected_product_code'] ?? ''))));
    }

    private static function whole(float $value): int|float
    {
        return floor($value) === $value && abs($value) < PHP_INT_MAX ? (int) $value : $value;
    }

    /** @param array<string, mixed> $row */
    public function seal(string $email, DateTimeImmutable $now, array $row): string
    {
        $seal = $this->seal ?? throw new InvalidRfqValue('Accessories are not available on this server.');

        return $seal->seal($email, $now, $row);
    }

    /**
     * The row a token stands for, as a new line under `$lineUid`.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidRfqValue
     */
    public function open(string $token, string $lineUid, string $email, DateTimeImmutable $now): array
    {
        $seal = $this->seal ?? throw new InvalidRfqValue('Accessories are not available on this server.');
        if (!StoreFormat::isId($lineUid)) {
            throw new InvalidRfqValue(sprintf('new line %s needs an unused 32-character id.', $lineUid));
        }
        $row = $seal->open($token, $email, $now);
        $row['line_uid'] = $lineUid;

        return $row;
    }
}
