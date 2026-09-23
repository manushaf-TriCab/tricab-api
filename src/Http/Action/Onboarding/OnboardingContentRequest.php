<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Onboarding;

use TriCab\Core\Onboarding\Application\DTO\Authoring\ContentItemRecord;
use TriCab\Core\Onboarding\Application\DTO\Authoring\ContentVersionRecord;
use TriCab\Core\Onboarding\Domain\Exception\InvalidOnboardingValue;
use TriCab\Core\Product\Domain\Exception\InvalidProductValue;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;

/**
 * Shared parsing for the 4.4 authoring routes.
 *
 * The item type is a route argument — `/content/training`, `/content/policies`,
 * `/content/forms` — set by the route table, never read from the body, for the
 * same reason the trainee routes do it: a client cannot ask for a policy "as a
 * form" to see what a different code path does with it.
 */
final class OnboardingContentRequest
{
    /**
     * @param array<string, string> $arguments
     */
    public static function itemType(array $arguments): string
    {
        $type = $arguments['itemType'] ?? '';

        return $type;
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function rowVersion(array $body): RowVersionToken
    {
        $value = $body['rowVersion'] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw InvalidOnboardingValue::for('rowVersion', '(missing)');
        }
        try {
            return RowVersionToken::fromBase64(trim($value));
        } catch (InvalidProductValue) {
            throw InvalidOnboardingValue::for('rowVersion', '(malformed)');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function version(ContentItemRecord $item, ContentVersionRecord $version): array
    {
        return ['item' => $item->toArray(), 'version' => $version->toArray($item->accessGroup)];
    }
}
