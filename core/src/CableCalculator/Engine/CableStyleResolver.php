<?php

declare(strict_types=1);

namespace TriCab\Core\CableCalculator\Engine;

final class CableStyleResolver
{
    /** @var array<string, string|list<string>> */
    public const STYLE_AR = [
        'xlpe_epr' => "('XL')",
        'xlpe_epr_110' => "('KL')",
        'fr' => "('FR','FH')",
        'xlpe_epr_dc_110' => "('DL')",
        'xlpe_epr_dc_90' => "('FP')",
        'lszh_epr_dc' => "('SF')",
        'lszh' => "('CC')",
        'unbraided' => "('KK')",
        'braided' => "('OD')",
        'vfd' => ["('XU')", "('KU')"],
        'switchboard' => ["('LP')", "('LS')"],
        'dc_multi' => "('KB')",
        'lszh_dc_mult' => "('SD')",
        'shipboard' => "('BV')",
        'shipboard(braided)' => "('DV')",
        'offshore' => "('PJ')",
    ];

    /** Installation methods that are buried: spaced/touching conduit (3, 7) and spaced/touching direct (4, 9). */
    private const BURIED_INSTALLATIONS = [3, 4, 7, 9];

    /**
     * @return array{0: string|list<string>, 1: string, 2: int}
     */
    public static function suggestCableStyle(int $type, int $operating, int $phase): array
    {
        $earthStyle = 'LP';

        switch ($type) {
            case 0:
                $earthStyle = 'LP';
                $cableStyles = ($operating === 90) ? self::STYLE_AR['switchboard'][0] : self::STYLE_AR['switchboard'][1];
                break;
            case 1:
            case 4:
                $earthStyle = 'LP';
                if ($phase === 5) {
                    $cableStyles = ($type === 1)
                        ? (($operating === 90) ? self::STYLE_AR['xlpe_epr_dc_90'] : self::STYLE_AR['xlpe_epr_dc_110'])
                        : self::STYLE_AR['dc_multi'];
                } else {
                    $cableStyles = ($operating === 90) ? self::STYLE_AR['xlpe_epr'] : self::STYLE_AR['xlpe_epr_110'];
                }
                break;
            case 2:
            case 5:
                $earthStyle = 'LS';
                $operating = 110;
                $cableStyles = self::STYLE_AR['lszh'];
                if ($phase === 5) {
                    $cableStyles = ($type === 2) ? self::STYLE_AR['lszh_epr_dc'] : self::STYLE_AR['lszh_dc_mult'];
                }
                break;
            case 3:
                $earthStyle = 'LS';
                // fall through
            case 6:
                $operating = 110;
                $cableStyles = self::STYLE_AR['fr'];
                break;
            case 7:
                $operating = 110;
                $cableStyles = self::STYLE_AR['unbraided'];
                break;
            case 8:
                $operating = 110;
                $cableStyles = self::STYLE_AR['braided'];
                break;
            case 9:
                $cableStyles = ($operating === 90) ? self::STYLE_AR['vfd'][0] : self::STYLE_AR['vfd'][1];
                break;
            case 10:
            case 13:
                $earthStyle = 'UP';
                $operating = 90;
                $cableStyles = self::STYLE_AR['shipboard'];
                break;
            case 11:
            case 14:
                $earthStyle = 'UP';
                $operating = 90;
                $cableStyles = self::STYLE_AR['shipboard(braided)'];
                break;
            case 12:
            case 15:
                $earthStyle = 'UP';
                $operating = 90;
                $cableStyles = self::STYLE_AR['offshore'];
                break;
            default:
                $earthStyle = 'LP';
                $cableStyles = self::STYLE_AR['xlpe_epr'];
        }

        return [$cableStyles, $earthStyle, $operating];
    }

    /**
     * TSD rule: an aluminium earth on a buried SDI run is KL-PAXA rather than LP-XXXA.
     * Earth sizing is unchanged; only the earth style (and so its code and fault constant) moves.
     * Mirrors buried_alum_earth_style() in legacy tc-web include-cable-calc.php.
     */
    public static function buriedAluminiumEarthStyle(string $earthStyle, int $type, bool $earthAluminium, int $installation): string
    {
        if ($earthStyle === 'LP' && $earthAluminium && $type >= 1 && $type <= 3
            && in_array($installation, self::BURIED_INSTALLATIONS, true)) {
            return 'KL';
        }

        return $earthStyle;
    }

    /**
     * @return list<string>
     */
    public static function parseStyleSql(string|array $styleSql): array
    {
        if (is_array($styleSql)) {
            $styles = [];
            foreach ($styleSql as $fragment) {
                $styles = array_merge($styles, self::parseStyleSql($fragment));
            }

            return array_values(array_unique($styles));
        }

        preg_match_all("/'([^']+)'/", $styleSql, $matches);

        return $matches[1] ?? [];
    }
}
