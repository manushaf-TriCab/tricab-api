<?php

declare(strict_types=1);

namespace TriCab\Core\PriceLookup\Domain;

/**
 * Mask codes: the price row a cable falls back to when it has no price of its own.
 *
 * A mask is the product code with the two core-configuration characters replaced by XX and the
 * colour dropped; armour and conductor letters stay. KL-PASN/4C16OR → KL-XXSN/4C16.
 * Same shape tc-web's ProductConfigurator joins price_mask on.
 *
 * Size is mm² (C cores, P pairs, T triads, Q quads) or AWG (A), with or without a leading
 * zero (1C.75) and with AWG aughts (1A4/0). Colour is whatever follows the size, starting
 * with a letter (BK, G1, G/Y).
 */
final class PriceLookupColourMask
{
    /** Jacket letters that may end a style: UPA/UPB/UPC/UPM are jacket variants of UP. */
    private const JACKET_LETTERS = ['A', 'B', 'C', 'M'];

    private const CODE_PATTERN = '/^([A-Z0-9]{2,4})-([A-Z0-9]{2})([A-Z0-9]{2,3})\/([0-9]{1,2}[CPTQA](?:[0-9]+(?:\.[0-9]+)?|\.[0-9]+)(?:\/0)?)([A-Z][A-Z0-9\/]*)?$/';

    /** Keep in step with SQL_MASK_PATTERN. */
    private const MASK_PATTERN = '/^[A-Z0-9]{2,4}-XX[A-Z0-9]{2,3}\/[0-9]{1,2}[CPTQA](?:[0-9]+(?:\.[0-9]+)?|\.[0-9]+)(?:\/0)?$/';

    /**
     * MySQL REGEXP twin of MASK_PATTERN, for keeping masks out of search results.
     * Keep in step with MASK_PATTERN.
     */
    public const SQL_MASK_PATTERN = '^[A-Z0-9]{2,4}-XX[A-Z0-9]{2,3}/[0-9]{1,2}[CPTQA]([0-9]+([.][0-9]+)?|[.][0-9]+)(/0)?$';

    /**
     * The code's own mask, or null when the code isn't a full cable code (partials, wildcards).
     */
    public function maskForLookup(string $productCode): ?string
    {
        return $this->masksForLookup($productCode)[0] ?? null;
    }

    /**
     * Masks to try in order: the code's own mask, then, for a jacket-variant style, the base
     * style's mask (UPA-XXXD/1C120BK → UPA-XXXD/1C120, then UP-XXXD/1C120).
     *
     * @return list<string>
     */
    public function masksForLookup(string $productCode): array
    {
        $code = strtoupper(trim($productCode));
        if (preg_match(self::CODE_PATTERN, $code, $m) !== 1) {
            return [];
        }

        [, $style, , $armourConductor, $size] = $m;
        $tail = '-XX' . $armourConductor . '/' . $size;
        $masks = [$style . $tail];

        if (strlen($style) >= 3 && in_array(substr($style, -1), self::JACKET_LETTERS, true)) {
            $masks[] = substr($style, 0, -1) . $tail;
        }

        return $masks;
    }

    /**
     * A code is a mask only when the configuration is XX and there is no colour.
     * LS-XXXN/1C120BK is a real base cable, not a mask.
     */
    public function isMask(string $code): bool
    {
        return preg_match(self::MASK_PATTERN, strtoupper(trim($code))) === 1;
    }
}
