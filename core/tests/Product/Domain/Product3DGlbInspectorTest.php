<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Tests\Support\GlbFixture;
use TriCab\Core\Product\Domain\Exception\Product3DGlbInvalid;
use TriCab\Core\Product\Domain\Service\Product3DGlbInspector;

final class Product3DGlbInspectorTest extends TestCase
{
    public function test_accepts_minimal_valid_glb_and_allowed_extensions(): void
    {
        $glb = GlbFixture::build([
            'extensionsUsed' => ['KHR_materials_pbrSpecularGlossiness', 'KHR_texture_transform', 'KHR_mesh_quantization'],
            'extensionsRequired' => ['EXT_meshopt_compression'],
            'buffers' => [['byteLength' => 4, 'uri' => 'data:application/octet-stream;base64,AAAAAA==']],
        ]);
        $result = (new Product3DGlbInspector())->inspect($glb);

        self::assertSame(1, $result['meshCount']);
        self::assertSame(strlen($glb), $result['byteLength']);
    }

    /**
     * @param callable(): string $build
     */
    #[DataProvider('invalidCases')]
    public function test_rejects_invalid_glb_with_stable_code(callable $build, string $code): void
    {
        try {
            (new Product3DGlbInspector())->inspect($build());
            self::fail('Expected Product3DGlbInvalid');
        } catch (Product3DGlbInvalid $e) {
            self::assertSame($code, $e->errorCode);
        }
    }

    /**
     * @return array<string, array{callable(): string, string}>
     */
    public static function invalidCases(): array
    {
        return [
            'magic' => [static fn (): string => 'XXXX' . substr(GlbFixture::build(), 4), Product3DGlbInvalid::BAD_MAGIC],
            'tiny' => [static fn (): string => 'glTF', Product3DGlbInvalid::BAD_MAGIC],
            'version' => [static fn (): string => substr_replace(GlbFixture::build(), pack('V', 1), 4, 4), Product3DGlbInvalid::BAD_VERSION],
            'length' => [static fn (): string => GlbFixture::build() . 'junk', Product3DGlbInvalid::BAD_LENGTH],
            'first chunk not json' => [static fn (): string => substr_replace(GlbFixture::build(), 'BIN' . "\0", 16, 4), Product3DGlbInvalid::BAD_JSON_CHUNK],
            'json does not parse' => [static function (): string {
                $text = '{not json}  ';

                return 'glTF' . pack('V', 2) . pack('V', 20 + strlen($text)) . pack('V', strlen($text)) . 'JSON' . $text;
            }, Product3DGlbInvalid::BAD_JSON_CHUNK],
            'no mesh' => [static fn (): string => GlbFixture::build(['meshes' => []]), Product3DGlbInvalid::NO_MESH],
            'external buffer' => [static fn (): string => GlbFixture::build(['buffers' => [['byteLength' => 1, 'uri' => 'https://evil.test/a.bin']]]), Product3DGlbInvalid::EXTERNAL_URI],
            'external image' => [static fn (): string => GlbFixture::build(['images' => [['uri' => 'tex.png']]]), Product3DGlbInvalid::EXTERNAL_URI],
            'lights extension' => [static fn (): string => GlbFixture::build(['extensionsUsed' => ['KHR_lights_punctual']]), Product3DGlbInvalid::FORBIDDEN_EXTENSION],
            'unknown required' => [static fn (): string => GlbFixture::build(['extensionsRequired' => ['VENDOR_custom']]), Product3DGlbInvalid::FORBIDDEN_EXTENSION],
        ];
    }

    public function test_size_cap_and_header_only_check(): void
    {
        $inspector = new Product3DGlbInspector();
        try {
            $inspector->inspect(GlbFixture::build([], 2048), 1024);
            self::fail('Expected size cap');
        } catch (Product3DGlbInvalid $e) {
            self::assertSame(Product3DGlbInvalid::TOO_LARGE, $e->errorCode);
        }

        $glb = GlbFixture::build();
        $inspector->inspectHeader(substr($glb, 0, 12), strlen($glb));
        $this->expectException(Product3DGlbInvalid::class);
        $inspector->inspectHeader(substr($glb, 0, 12), strlen($glb) + 1);
    }
}
