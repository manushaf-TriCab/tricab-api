<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Domain\ValueObject;

use TriCab\Core\Onboarding\Domain\Exception\InvalidOnboardingValue;

/**
 * A drawn signature, as a signature field's answer (5.4): a PNG the browser
 * drew, sent as a `data:image/png;base64,` URL — the source's
 * `signatureDataUrl`, kept in the same place, beside the answers it signs.
 *
 * Checked before it is stored, because a submission is immutable: it must be a
 * real PNG, of a size a signature pad produces, and not so large that a form
 * payload becomes an upload channel.
 */
final readonly class SignatureImage
{
    public const PREFIX = 'data:image/png;base64,';
    public const MAX_BYTES = 200_000;
    private const MIN_WIDTH = 60;
    private const MIN_HEIGHT = 20;
    private const MAX_SIDE = 2400;

    private function __construct(
        public string $png,
        public int $width,
        public int $height,
    ) {
    }

    /**
     * @throws InvalidOnboardingValue naming the field by its label
     */
    public static function fromDataUrl(string $label, mixed $value): self
    {
        if (!is_string($value) || !str_starts_with($value, self::PREFIX)) {
            throw InvalidOnboardingValue::for($label, 'not a drawn signature');
        }
        $encoded = substr($value, strlen(self::PREFIX));
        if (strlen($encoded) > (int) ceil(self::MAX_BYTES * 4 / 3) + 4) {
            throw InvalidOnboardingValue::for($label, 'the signature image is too large');
        }
        $png = base64_decode($encoded, true);
        if ($png === false || !str_starts_with($png, "\x89PNG\r\n\x1a\n")) {
            throw InvalidOnboardingValue::for($label, 'not a PNG image');
        }
        $size = @getimagesizefromstring($png);
        if ($size === false || ($size[2] ?? null) !== IMAGETYPE_PNG) {
            throw InvalidOnboardingValue::for($label, 'not a readable PNG image');
        }
        [$width, $height] = [$size[0], $size[1]];
        if ($width < self::MIN_WIDTH || $height < self::MIN_HEIGHT || $width > self::MAX_SIDE || $height > self::MAX_SIDE) {
            throw InvalidOnboardingValue::for($label, sprintf('a %d×%d image is not the size of a signature', $width, $height));
        }

        return new self($png, $width, $height);
    }
}
