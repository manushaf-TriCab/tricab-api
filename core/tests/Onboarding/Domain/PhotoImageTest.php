<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Onboarding\Domain;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Onboarding\Domain\Exception\InvalidOnboardingValue;
use TriCab\Core\Onboarding\Domain\ValueObject\PhotoImage;

/**
 * A photo is stored without the metadata a phone writes into it — GPS
 * position, camera serial, time taken — whatever the client did first. The
 * source re-encoded for this (work order 0.7 C, "EXIF stripping is a privacy
 * control, not an optimisation"); found missing in the Phase 8 review.
 */
final class PhotoImageTest extends TestCase
{
    public function test_exif_xmp_comments_and_mpf_are_removed_and_the_picture_is_untouched(): void
    {
        $plain = self::jpeg();
        $planted = self::withSegments($plain, [
            self::segment(0xE1, "Exif\0\0GPSLatitude -37.8136 SerialNumber CAM-0042"),
            self::segment(0xE1, 'http://ns.adobe.com/xap/1.0/' . "\0" . '<x:xmpmeta>GPSLongitude 144.9631</x:xmpmeta>'),
            self::segment(0xE2, "MPF\0II*\0hidden-second-image"),
            self::segment(0xE2, "ICC_PROFILE\0\x01\x01colour-profile"),
            self::segment(0xED, 'Photoshop 3.0' . "\0" . '8BIM location'),
            self::segment(0xFE, 'Taken at 12 Example St by Sam'),
        ]);

        $photo = PhotoImage::fromBase64(base64_encode($planted));

        foreach (['Exif', 'GPSLatitude', 'GPSLongitude', 'CAM-0042', 'MPF', 'hidden-second-image', '8BIM', 'Example St'] as $leak) {
            self::assertStringNotContainsString($leak, $photo->jpeg);
        }
        self::assertStringContainsString("ICC_PROFILE\0", $photo->jpeg, 'a colour profile changes how it looks, so it stays');
        self::assertSame(self::scan($plain), substr($photo->jpeg, -strlen(self::scan($plain))), 'the compressed picture is copied, not re-encoded');
        self::assertSame([$photo->width, $photo->height], array_slice((array) getimagesizefromstring($photo->jpeg), 0, 2));
        self::assertNotFalse(@imagecreatefromstring($photo->jpeg));
    }

    public function test_a_photo_already_without_metadata_comes_through_unchanged(): void
    {
        $once = PhotoImage::fromBase64(base64_encode(self::jpeg()))->jpeg;

        self::assertSame($once, PhotoImage::fromBase64(base64_encode($once))->jpeg);
    }

    public function test_a_jpeg_cut_off_before_its_picture_is_refused(): void
    {
        $plain = self::jpeg();
        $truncated = substr($plain, 0, strpos($plain, "\xFF\xDA") ?: 0);

        $this->expectException(InvalidOnboardingValue::class);

        PhotoImage::fromBase64(base64_encode($truncated));
    }

    private static function jpeg(): string
    {
        $image = imagecreatetruecolor(160, 120);
        imagefilledrectangle($image, 0, 0, 159, 119, (int) imagecolorallocate($image, 30, 120, 200));
        ob_start();
        imagejpeg($image);

        return (string) ob_get_clean();
    }

    private static function segment(int $marker, string $payload): string
    {
        return "\xFF" . chr($marker) . pack('n', strlen($payload) + 2) . $payload;
    }

    /**
     * @param list<string> $segments
     */
    private static function withSegments(string $jpeg, array $segments): string
    {
        return "\xFF\xD8" . implode('', $segments) . substr($jpeg, 2);
    }

    private static function scan(string $jpeg): string
    {
        return substr($jpeg, (int) strpos($jpeg, "\xFF\xDA"));
    }
}
