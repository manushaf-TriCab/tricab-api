<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Service;

/**
 * `difflib.SequenceMatcher(None, a, b).ratio()`, step for step, because the suggestion scores and so
 * the ranking and the 0.55 threshold depend on its exact answer (it is not an edit distance).
 *
 * Ported with `isjunk=None` and `autojunk=True`, the defaults the source used: with a second string of
 * 200 characters or more, any character occurring in more than 1% of it (plus one) is "popular" and
 * is not used to seed a match, though a match is still extended over it. The inputs are the
 * normalised names (ASCII), so the strings are compared byte by byte.
 */
final class ApVendorSimilarity
{
    private const AUTOJUNK_SIZE = 200;

    public static function ratio(string $a, string $b): float
    {
        $length = strlen($a) + strlen($b);
        if ($length === 0) {
            return 1.0;
        }

        return 2.0 * self::matchingCharacters($a, $b) / $length;
    }

    private static function matchingCharacters(string $a, string $b): int
    {
        $lengthA = strlen($a);
        $lengthB = strlen($b);

        // chain_b: each character of b to the ascending positions it occurs at.
        $positions = [];
        for ($j = 0; $j < $lengthB; $j++) {
            $positions[$b[$j]][] = $j;
        }
        if ($lengthB >= self::AUTOJUNK_SIZE) {
            $limit = intdiv($lengthB, 100) + 1;
            foreach ($positions as $character => $found) {
                if (count($found) > $limit) {
                    unset($positions[$character]);
                }
            }
        }

        $total = 0;
        /** @var list<array{0: int, 1: int, 2: int, 3: int}> $queue the ranges still to search: a low and high in a, then in b */
        $queue = [[0, $lengthA, 0, $lengthB]];
        while ($queue !== []) {
            [$aLow, $aHigh, $bLow, $bHigh] = array_pop($queue);
            [$i, $j, $size] = self::longestMatch($a, $b, $positions, $aLow, $aHigh, $bLow, $bHigh);
            if ($size === 0) {
                continue;
            }
            $total += $size;
            if ($aLow < $i && $bLow < $j) {
                $queue[] = [$aLow, $i, $bLow, $j];
            }
            if ($i + $size < $aHigh && $j + $size < $bHigh) {
                $queue[] = [$i + $size, $aHigh, $j + $size, $bHigh];
            }
        }

        return $total;
    }

    /**
     * `find_longest_match`: the earliest-in-a, then earliest-in-b, longest block.
     *
     * @param array<int|string, list<int>> $positions
     * @return array{0: int, 1: int, 2: int}
     */
    private static function longestMatch(string $a, string $b, array $positions, int $aLow, int $aHigh, int $bLow, int $bHigh): array
    {
        $bestI = $aLow;
        $bestJ = $bLow;
        $bestSize = 0;
        $runs = [];
        for ($i = $aLow; $i < $aHigh; $i++) {
            $next = [];
            foreach ($positions[$a[$i]] ?? [] as $j) {
                if ($j < $bLow) {
                    continue;
                }
                if ($j >= $bHigh) {
                    break;
                }
                $k = $next[$j] = ($runs[$j - 1] ?? 0) + 1;
                if ($k > $bestSize) {
                    $bestI = $i - $k + 1;
                    $bestJ = $j - $k + 1;
                    $bestSize = $k;
                }
            }
            $runs = $next;
        }

        // Extend over characters the popular-character rule left out of the index (there is no junk).
        while ($bestI > $aLow && $bestJ > $bLow && $a[$bestI - 1] === $b[$bestJ - 1]) {
            $bestI--;
            $bestJ--;
            $bestSize++;
        }
        while ($bestI + $bestSize < $aHigh && $bestJ + $bestSize < $bHigh && $a[$bestI + $bestSize] === $b[$bestJ + $bestSize]) {
            $bestSize++;
        }

        return [$bestI, $bestJ, $bestSize];
    }
}
