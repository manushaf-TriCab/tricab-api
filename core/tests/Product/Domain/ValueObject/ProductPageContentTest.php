<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Domain\ValueObject;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Product\Domain\Exception\InvalidProductValue;
use TriCab\Core\Product\Domain\ValueObject\ProductPageContent;

final class ProductPageContentTest extends TestCase
{
    public function test_defaults_render_products_as_before(): void
    {
        $d = ProductPageContent::defaults();
        self::assertFalse($d->showProductMarking(), 'Product Marking is OFF unless an administrator enabled it');
        self::assertNull($d->version());
        self::assertNull($d->coreColoursNote());
        self::assertTrue($d->showCoreColoursNote());
    }

    public function test_resolve_tolerates_missing_values(): void
    {
        $c = ProductPageContent::resolve(null, null, null);
        self::assertFalse($c->showProductMarking());
        self::assertTrue($c->showCoreColoursNote());
        self::assertNull(ProductPageContent::resolve(true, "   \n ", true)->coreColoursNote());
    }

    public function test_explicit_settings_are_honoured_and_the_version_is_carried_separately(): void
    {
        self::assertTrue(ProductPageContent::resolve(true, null, true)->showProductMarking());
        self::assertFalse(ProductPageContent::resolve(false, null, true)->showProductMarking());
        $v = ProductPageContent::resolve(true, 'n', false)->withVersion('2026-10-14T01:02:03.1234567');
        self::assertSame('2026-10-14T01:02:03.1234567', $v->version());
        self::assertTrue($v->showProductMarking());
        self::assertSame('n', $v->coreColoursNote());
        self::assertFalse($v->showCoreColoursNote());
    }

    public function test_note_is_trimmed_and_line_endings_normalised_but_interior_breaks_are_kept(): void
    {
        self::assertSame("a\nb", ProductPageContent::fromInput(true, " a\r\nb \r", true)->coreColoursNote());
    }

    public function test_note_length_limit_counts_characters(): void
    {
        self::assertNotNull(ProductPageContent::fromInput(true, str_repeat('é', ProductPageContent::NOTE_MAX_LENGTH), true)->coreColoursNote());
        $this->expectException(InvalidProductValue::class);
        ProductPageContent::fromInput(true, str_repeat('é', ProductPageContent::NOTE_MAX_LENGTH + 1), true);
    }

    /**
     * @dataProvider invalid
     */
    public function test_rejects_invalid_input(mixed $marking, mixed $note, mixed $showNote): void
    {
        $this->expectException(InvalidProductValue::class);
        ProductPageContent::fromInput($marking, $note, $showNote);
    }

    /**
     * @return iterable<string, array{mixed, mixed, mixed}>
     */
    public static function invalid(): iterable
    {
        yield 'marking not bool' => ['1', null, true];
        yield 'note not bool' => [true, null, 1];
        yield 'note not string' => [true, ['x'], true];
        yield 'nul byte' => [true, "a\0b", true];
    }
}
