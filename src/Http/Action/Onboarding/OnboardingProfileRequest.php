<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Onboarding;

use TriCab\Core\Onboarding\Application\DTO\Admin\OnboardingProfileFields;
use TriCab\Core\Onboarding\Domain\Exception\InvalidOnboardingValue;

/** Parses the profile half of the 4.1 request bodies, shared by create and edit. */
final class OnboardingProfileRequest
{
    /**
     * @param array<string, mixed> $body
     */
    public static function fields(array $body): OnboardingProfileFields
    {
        return OnboardingProfileFields::of(
            self::text($body, 'position'),
            self::text($body, 'department'),
            self::text($body, 'branch'),
            self::text($body, 'startDate'),
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function text(array $body, string $field): ?string
    {
        $value = $body[$field] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw InvalidOnboardingValue::for($field, '(not text)');
        }

        return $value;
    }
}
