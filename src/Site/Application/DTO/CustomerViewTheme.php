<?php

declare(strict_types=1);

namespace TriCab\Core\Site\Application\DTO;

/**
 * Shared catalogue datasheet theme. Unknown keys from clients are ignored;
 * missing keys fall back to catalogue defaults.
 *
 * @phpstan-type ThemeArray array{
 *   fonts: array{family: string},
 *   colors: array{
 *     pageBg: string,
 *     panelBeige: string,
 *     panelWhite: string,
 *     heading: string,
 *     text: string,
 *     muted: string,
 *     border: string,
 *     accent: string,
 *     charcoal: string,
 *     codeGradient: string,
 *     tableHeader: string,
 *     rowAlt: string
 *   },
 *   layout: array{
 *     canvasMaxWidth: int,
 *     heroMinHeight: int,
 *     colMain: float,
 *     colApps: float,
 *     colConstruction: float,
 *     panelRadius: int,
 *     panelPadding: float
 *   },
 *   panels: array{
 *     applications: array{visible: bool, title: string},
 *     construction: array{visible: bool, title: string},
 *     coreColours: array{visible: bool, title: string},
 *     variantMatrix: array{visible: bool, title: string},
 *     documents: array{visible: bool, title: string},
 *     relationships: array{visible: bool, title: string}
 *   }
 * }
 */
final class CustomerViewTheme
{
    public const CONFIG_KEY = 'customer-view.theme';

    /** @return ThemeArray */
    public static function defaults(): array
    {
        return [
            'fonts' => [
                'family' => "'Plus Jakarta Sans', 'Segoe UI', Arial, sans-serif",
            ],
            'colors' => [
                'pageBg' => '#ffffff',
                'panelBeige' => '#e8e3db',
                'panelWhite' => '#ffffff',
                'heading' => '#0b71c5',
                'text' => '#151515',
                'muted' => '#5a6168',
                'border' => '#cfc8bf',
                'accent' => '#f26522',
                'charcoal' => '#292929',
                'codeGradient' => 'linear-gradient(160deg, #0a7ea4 0%, #12b5b0 55%, #1cc9b5 100%)',
                'tableHeader' => '#1f1f1f',
                'rowAlt' => '#f0eeea',
            ],
            'layout' => [
                'canvasMaxWidth' => 1700,
                'heroMinHeight' => 640,
                'colMain' => 0.2,
                'colApps' => 0.32,
                'colConstruction' => 0.48,
                'panelRadius' => 0,
                'panelPadding' => 1.35,
            ],
            'panels' => [
                'applications' => ['visible' => true, 'title' => 'Typical Applications'],
                'construction' => ['visible' => true, 'title' => 'Construction'],
                'coreColours' => ['visible' => true, 'title' => 'Core colours'],
                'variantMatrix' => ['visible' => true, 'title' => 'Technical Dimensions'],
                'documents' => ['visible' => true, 'title' => 'Documentation'],
                'relationships' => ['visible' => true, 'title' => 'Related'],
            ],
        ];
    }

    /**
     * @param array<string, mixed>|null $input
     * @return ThemeArray
     */
    public static function merge(?array $input): array
    {
        $defaults = self::defaults();
        if ($input === null || $input === []) {
            return $defaults;
        }

        return [
            'fonts' => self::mergeFonts($defaults['fonts'], $input['fonts'] ?? null),
            'colors' => self::mergeColors($defaults['colors'], $input['colors'] ?? null),
            'layout' => self::mergeLayout($defaults['layout'], $input['layout'] ?? null),
            'panels' => self::mergePanels($defaults['panels'], $input['panels'] ?? null),
        ];
    }

    /**
     * @param array{family: string} $defaults
     * @param mixed $input
     * @return array{family: string}
     */
    private static function mergeFonts(array $defaults, mixed $input): array
    {
        if (!is_array($input)) {
            return $defaults;
        }
        $family = $input['family'] ?? null;
        if (is_string($family) && trim($family) !== '') {
            $defaults['family'] = trim($family);
        }

        return $defaults;
    }

    /**
     * @param array<string, string> $defaults
     * @param mixed $input
     * @return array<string, string>
     */
    private static function mergeColors(array $defaults, mixed $input): array
    {
        if (!is_array($input)) {
            return $defaults;
        }
        foreach ($defaults as $key => $value) {
            $candidate = $input[$key] ?? null;
            if (is_string($candidate) && trim($candidate) !== '') {
                $defaults[$key] = trim($candidate);
            }
        }

        return $defaults;
    }

    /**
     * @param array<string, int|float> $defaults
     * @param mixed $input
     * @return array<string, int|float>
     */
    private static function mergeLayout(array $defaults, mixed $input): array
    {
        if (!is_array($input)) {
            return $defaults;
        }
        foreach (['canvasMaxWidth', 'heroMinHeight', 'panelRadius'] as $intKey) {
            if (isset($input[$intKey]) && is_numeric($input[$intKey])) {
                $defaults[$intKey] = max(0, (int) $input[$intKey]);
            }
        }
        foreach (['colMain', 'colApps', 'colConstruction', 'panelPadding'] as $floatKey) {
            if (isset($input[$floatKey]) && is_numeric($input[$floatKey])) {
                $defaults[$floatKey] = max(0.0, (float) $input[$floatKey]);
            }
        }

        return $defaults;
    }

    /**
     * @param array<string, array{visible: bool, title: string}> $defaults
     * @param mixed $input
     * @return array<string, array{visible: bool, title: string}>
     */
    private static function mergePanels(array $defaults, mixed $input): array
    {
        if (!is_array($input)) {
            return $defaults;
        }
        foreach ($defaults as $panelKey => $panelDefaults) {
            $candidate = $input[$panelKey] ?? null;
            if (!is_array($candidate)) {
                continue;
            }
            if (array_key_exists('visible', $candidate)) {
                $panelDefaults['visible'] = (bool) $candidate['visible'];
            }
            $title = $candidate['title'] ?? null;
            if (is_string($title) && trim($title) !== '') {
                $panelDefaults['title'] = trim($title);
            }
            $defaults[$panelKey] = $panelDefaults;
        }

        return $defaults;
    }
}
