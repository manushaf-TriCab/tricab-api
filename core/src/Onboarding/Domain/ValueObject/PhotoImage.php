<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Domain\ValueObject;

use TriCab\Core\Onboarding\Domain\Exception\InvalidOnboardingValue;

/**
 * A photograph for a form's `photo` field (5.4) — the vehicle report's
 * condition photos. JPEG only, as the source took them, and checked before it
 * is stored: a real JPEG, of a photograph's size, not so large that an upload
 * becomes a way to park files.
 */
final readonly class PhotoImage
{
    public const MAX_BYTES = 5_000_000;
    public const MAX_PER_FIELD = 4;
    private const MIN_SIDE = 100;
    private const MAX_SIDE = 8000;

    private function __construct(
        public string $jpeg,
        public int $width,
        public int $height,
    ) {
    }

    /**
     * @throws InvalidOnboardingValue
     */
    public static function fromBase64(mixed $value): self
    {
        if (!is_string($value) || $value === '') {
            throw InvalidOnboardingValue::for('photo', '(missing)');
        }
        if (strlen($value) > (int) ceil(self::MAX_BYTES * 4 / 3) + 4) {
            throw InvalidOnboardingValue::for('photo', 'larger than 5 MB');
        }
        $jpeg = base64_decode($value, true);
        if ($jpeg === false || !str_starts_with($jpeg, "\xFF\xD8\xFF")) {
            throw InvalidOnboardingValue::for('photo', 'not a JPEG image');
        }
        $size = @getimagesizefromstring($jpeg);
        if ($size === false || ($size[2] ?? null) !== IMAGETYPE_JPEG) {
            throw InvalidOnboardingValue::for('photo', 'not a readable JPEG image');
        }
        if ($size[0] < self::MIN_SIDE || $size[1] < self::MIN_SIDE || $size[0] > self::MAX_SIDE || $size[1] > self::MAX_SIDE) {
            throw InvalidOnboardingValue::for('photo', sprintf('a %d×%d image is not the size of a photograph', $size[0], $size[1]));
        }

        return new self(self::withoutMetadata($jpeg), $size[0], $size[1]);
    }

    /**
     * The same picture with its metadata segments removed: EXIF (which carries
     * GPS position, camera serial and time taken), XMP, maker notes, comments
     * and the MPF segment that can hide a second full image. The source
     * re-encoded for this; removing segments does the same job without
     * re-compressing, so the picture is unchanged byte for byte from the scan
     * onwards. Kept: JFIF (APP0), a colour profile (APP2 ICC_PROFILE) and
     * Adobe's colour-transform flag (APP14), which change how it looks.
     *
     * The browser already re-draws a photo before upload, which strips all of
     * this; this is the server holding the line for a client that does not.
     * Orientation lives in EXIF, so it is gone too — the browser's re-draw has
     * already applied it.
     *
     * @throws InvalidOnboardingValue
     */
    private static function withoutMetadata(string $jpeg): string
    {
        $out = "\xFF\xD8";
        $length = strlen($jpeg);
        $at = 2;
        while ($at + 4 <= $length) {
            if ($jpeg[$at] !== "\xFF") {
                break;
            }
            $marker = ord($jpeg[$at + 1]);
            if ($marker === 0xFF) {
                ++$at; // fill byte

                continue;
            }
            $segmentLength = (ord($jpeg[$at + 2]) << 8) | ord($jpeg[$at + 3]);
            if ($segmentLength < 2 || $at + 2 + $segmentLength > $length) {
                break;
            }
            if ($marker === 0xDA) {
                // Start of scan: the compressed picture follows, to the end.
                return $out . substr($jpeg, $at);
            }
            $segment = substr($jpeg, $at, 2 + $segmentLength);
            $keep = match (true) {
                $marker === 0xE0, $marker === 0xEE => true,
                $marker === 0xE2 => str_starts_with(substr($segment, 4), "ICC_PROFILE\0"),
                $marker >= 0xE1 && $marker <= 0xEF, $marker === 0xFE => false,
                default => true,
            };
            if ($keep) {
                $out .= $segment;
            }
            $at += 2 + $segmentLength;
        }

        throw InvalidOnboardingValue::for('photo', 'not a readable JPEG image');
    }

    /**
     * A photo field's answer: the documents' public ids, comma-separated, at
     * most {@see MAX_PER_FIELD}.
     *
     * @return list<string>
     * @throws InvalidOnboardingValue
     */
    public static function idsFromAnswer(string $label, mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (!is_string($value)) {
            throw InvalidOnboardingValue::for($label, '(not a list of photos)');
        }
        $ids = array_values(array_filter(array_map('trim', explode(',', strtolower($value))), static fn (string $id): bool => $id !== ''));
        if (count($ids) > self::MAX_PER_FIELD) {
            throw InvalidOnboardingValue::for($label, sprintf('more than %d photos', self::MAX_PER_FIELD));
        }
        foreach ($ids as $id) {
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id) !== 1) {
                throw InvalidOnboardingValue::for($label, 'not a list of uploaded photos');
            }
        }

        return $ids;
    }
}
