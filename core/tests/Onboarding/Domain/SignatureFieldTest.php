<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Onboarding\Domain;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Onboarding\Domain\Exception\InvalidOnboardingValue;
use TriCab\Core\Onboarding\Domain\Service\FormSchemaRules;
use TriCab\Core\Onboarding\Domain\Service\FormSubmissionRules;
use TriCab\Core\Onboarding\Domain\Service\ReviewFieldProjection;
use TriCab\Core\Onboarding\Domain\ValueObject\DataClass;
use TriCab\Core\Onboarding\Domain\ValueObject\SignatureImage;

/**
 * Phase 5.4 — the signature field: authorable, checked on submit, and shown to
 * a reviewer as "signed" rather than as a wall of base64.
 */
#[\PHPUnit\Framework\Attributes\RequiresPhpExtension('gd')]
final class SignatureFieldTest extends TestCase
{
    private const SCHEMA = ['fields' => [['path' => 'signature', 'label' => 'Your signature', 'type' => 'signature', 'required' => true]]];

    public function test_a_signature_field_can_be_authored(): void
    {
        $schema = FormSchemaRules::normalise([['path' => 'signature', 'label' => 'Your signature', 'type' => 'signature', 'required' => true]], 'hr');

        self::assertSame('signature', $schema['fields'][0]['type']);
    }

    public function test_a_drawn_png_is_accepted(): void
    {
        FormSubmissionRules::check(self::SCHEMA, ['signature' => self::png(400, 120)]);

        self::assertSame(400, SignatureImage::fromDataUrl('Your signature', self::png(400, 120))->width);
    }

    public function test_a_required_signature_cannot_be_left_empty(): void
    {
        $this->expectException(InvalidOnboardingValue::class);
        FormSubmissionRules::check(self::SCHEMA, ['signature' => '']);
    }

    /** @return iterable<string, array{string}> */
    public static function refused(): iterable
    {
        yield 'typed text' => ['Sam Synthetic'];
        yield 'a jpeg claimed as png' => [SignatureImage::PREFIX . base64_encode("\xFF\xD8\xFF\xE0 not a png")];
        yield 'not base64' => [SignatureImage::PREFIX . '%%%'];
        yield 'another image type' => ['data:image/svg+xml;base64,' . base64_encode('<svg/>')];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refused')]
    public function test_anything_but_a_real_png_is_refused(string $value): void
    {
        $this->expectException(InvalidOnboardingValue::class);
        FormSubmissionRules::check(self::SCHEMA, ['signature' => $value]);
    }

    public function test_an_image_the_wrong_size_for_a_signature_is_refused(): void
    {
        $this->expectException(InvalidOnboardingValue::class);
        SignatureImage::fromDataUrl('Your signature', self::png(10, 10));
    }

    public function test_an_oversized_payload_is_refused_before_it_is_decoded(): void
    {
        $this->expectException(InvalidOnboardingValue::class);
        $this->expectExceptionMessage('too large');
        SignatureImage::fromDataUrl('Your signature', SignatureImage::PREFIX . str_repeat('A', 300_000));
    }

    public function test_a_reviewer_is_told_it_is_signed_not_shown_the_bytes(): void
    {
        $fields = ReviewFieldProjection::project(self::SCHEMA, ['signature' => self::png(400, 120)], ['signature' => DataClass::ORDINARY_HR]);

        self::assertSame('Signed (drawn signature)', $fields[0]['value']);
    }

    public static function png(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        self::assertNotFalse($image);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 255, 255, 255));
        imageline($image, 5, (int) ($height / 2), $width - 5, (int) ($height / 3), (int) imagecolorallocate($image, 0, 0, 0));
        ob_start();
        imagepng($image);

        return SignatureImage::PREFIX . base64_encode((string) ob_get_clean());
    }
}
