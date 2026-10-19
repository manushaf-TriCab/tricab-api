<?php

declare(strict_types=1);

namespace TriCab\Core\Npr\Domain;

/**
 * JSON shape of answers. They are objects keyed by field id, but PHP cannot
 * tell an empty object from an empty list: this makes `[]` encode as `{}`, in
 * the stored column and in responses, as the portal's clients expect.
 */
final class NprJson
{
    /**
     * @param array<string, mixed> $answers
     */
    public static function answers(array $answers): array|\stdClass
    {
        return $answers === [] ? new \stdClass() : $answers;
    }

    /**
     * @param list<array<string, mixed>> $variants
     * @return list<array<string, mixed>>
     */
    public static function variants(array $variants): array
    {
        return array_map(static function (array $variant): array {
            $answers = $variant['answers'] ?? [];
            $variant['answers'] = is_array($answers) ? self::answers($answers) : new \stdClass();

            return $variant;
        }, $variants);
    }

    /**
     * @return array<string, mixed>
     */
    public static function decodeObject(string $json): array
    {
        $decoded = json_decode($json, true);

        /** @var array<string, mixed> */
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function decodeVariants(string $json): array
    {
        $decoded = json_decode($json, true);
        $variants = [];
        foreach (is_array($decoded) ? $decoded : [] as $variant) {
            if (is_array($variant)) {
                /** @var array<string, mixed> $variant */
                $variants[] = $variant;
            }
        }

        return $variants;
    }
}
