<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Onboarding\Domain;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Onboarding\Domain\Service\ReviewFieldProjection;
use TriCab\Core\Onboarding\Domain\ValueObject\DataClass;

/**
 * A reviewer reads a form's ordinary fields and is told which restricted ones
 * exist — never what they say. Synthetic values throughout.
 */
final class ReviewFieldProjectionTest extends TestCase
{
    private const SCHEMA = [
        'fields' => [
            ['path' => 'preferredName', 'label' => 'Preferred name', 'type' => 'text'],
            ['path' => 'parking', 'label' => 'Parking?', 'type' => 'select', 'options' => [['key' => 'yes', 'label' => 'Yes please'], ['key' => 'no', 'label' => 'No']]],
            ['path' => 'confirm', 'label' => 'Details correct', 'type' => 'checkbox'],
            ['path' => 'bank.accountNumber', 'label' => 'Account number', 'type' => 'text'],
            ['path' => 'medical.condition', 'label' => 'Condition', 'type' => 'textarea'],
            ['path' => 'notes', 'label' => 'Notes', 'type' => 'textarea'],
        ],
    ];

    private const CLASSES = [
        'preferredName' => DataClass::ORDINARY_HR,
        'parking' => DataClass::ORDINARY_HR,
        'confirm' => DataClass::ORDINARY_HR,
        'bank.accountNumber' => DataClass::PAYROLL,
        'medical.condition' => DataClass::RESTRICTED_HR,
        'notes' => DataClass::ORDINARY_HR,
    ];

    public function test_ordinary_fields_are_shown_as_the_person_saw_them(): void
    {
        $fields = self::byPath(ReviewFieldProjection::project(
            self::SCHEMA,
            ['preferredName' => 'Sam', 'parking' => 'yes', 'confirm' => true],
            self::CLASSES,
        ));

        self::assertSame('Sam', $fields['preferredName']['value']);
        self::assertSame('Preferred name', $fields['preferredName']['label']);
        self::assertSame('Yes please', $fields['parking']['value'], 'a select shows its label, not its key');
        self::assertSame('Yes', $fields['confirm']['value']);
        self::assertNull($fields['notes']['value'], 'left blank');
        self::assertFalse($fields['notes']['withheld']);
    }

    public function test_payroll_and_restricted_fields_are_withheld_even_when_a_value_reached_the_ordinary_half(): void
    {
        // The splitter should have removed these. The projection does not rely on it.
        $fields = self::byPath(ReviewFieldProjection::project(
            self::SCHEMA,
            ['bank.accountNumber' => '00012345', 'medical.condition' => 'synthetic'],
            self::CLASSES,
        ));

        foreach (['bank.accountNumber', 'medical.condition'] as $path) {
            self::assertTrue($fields[$path]['withheld'], $path);
            self::assertNull($fields[$path]['value'], $path);
        }

        $encoded = json_encode(ReviewFieldProjection::project(self::SCHEMA, ['bank.accountNumber' => '00012345'], self::CLASSES), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('00012345', $encoded);
    }

    public function test_the_schema_order_is_kept_and_unnamed_values_follow_it(): void
    {
        $fields = ReviewFieldProjection::project(
            self::SCHEMA,
            ['legacy.extra' => 'kept', 'preferredName' => 'Sam'],
            self::CLASSES,
        );

        self::assertSame(
            ['preferredName', 'parking', 'confirm', 'bank.accountNumber', 'medical.condition', 'notes', 'legacy.extra'],
            array_column($fields, 'path'),
        );
        self::assertSame('legacy.extra', $fields[6]['label']);
        self::assertSame('kept', $fields[6]['value']);
    }

    public function test_a_malformed_schema_still_shows_what_was_submitted(): void
    {
        $fields = ReviewFieldProjection::project(['fields' => 'not a list'], ['preferredName' => 'Sam'], self::CLASSES);

        self::assertSame([['path' => 'preferredName', 'label' => 'preferredName', 'value' => 'Sam', 'withheld' => false]], $fields);
    }

    /**
     * @param list<array{path: string, label: string, value: ?string, withheld: bool}> $fields
     *
     * @return array<string, array{path: string, label: string, value: ?string, withheld: bool}>
     */
    private static function byPath(array $fields): array
    {
        return array_column($fields, null, 'path');
    }
}
