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

        return new self($jpeg, $size[0], $size[1]);
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
