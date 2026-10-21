<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Support;

/**
 * Builds minimal valid GLB 2.0 binaries for tests.
 */
final class GlbFixture
{
    /**
     * @param array<string, mixed> $jsonOverrides merged over the default glTF JSON
     * @param int $minBytes pad the file (via JSON whitespace) up to at least this size
     */
    public static function build(array $jsonOverrides = [], int $minBytes = 0): string
    {
        $json = array_merge([
            'asset' => ['version' => '2.0'],
            'scenes' => [['nodes' => [0]]],
            'nodes' => [['mesh' => 0]],
            'meshes' => [['primitives' => [['attributes' => ['POSITION' => 0]]]]],
        ], $jsonOverrides);
        $text = json_encode($json, JSON_THROW_ON_ERROR);
        $total = 12 + 8 + strlen($text);
        if ($total < $minBytes) {
            $text .= str_repeat(' ', $minBytes - $total);
        }
        $text .= str_repeat(' ', (4 - strlen($text) % 4) % 4);

        return 'glTF' . pack('V', 2) . pack('V', 12 + 8 + strlen($text))
            . pack('V', strlen($text)) . 'JSON' . $text;
    }
}
