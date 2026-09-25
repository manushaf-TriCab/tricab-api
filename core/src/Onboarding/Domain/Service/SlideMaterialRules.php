<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Domain\Service;

use TriCab\Core\Onboarding\Domain\Exception\InvalidOnboardingValue;

/**
 * What a slide shows beside its id and title: the slide image, its alt text and
 * transcript, any video or audio played on it, and the links printed on it.
 *
 * All of it is optional — a slide that is only an id and a title is still a
 * slide, which is what every version authored before this carried. Absent and
 * empty are the same thing and are left out of the normalised slide, so a
 * manifest without material reads back byte-for-byte as it was stored.
 *
 * ## HTTPS only, absolute only
 *
 * Every URL here ends up in an `<img src>`, a `<video src>` or an `<a href>` on
 * a trainee's screen. So each one must be an absolute `https://` URL: no
 * `javascript:`, no `data:`, no relative path that would resolve against the
 * platform's own origin. The source deck manifests used paths relative to the
 * manifest; the import resolves them before they get here.
 *
 * Strict, like {@see \TriCab\Core\Onboarding\Domain\ValueObject\PresentationManifest}:
 * a bad value is the author's to fix and is reported at save time, naming the
 * slide.
 *
 * Pure: no database, no request, no clock.
 */
final class SlideMaterialRules
{
    public const MAX_URL_LENGTH = 2048;
    public const MAX_ALT_TEXT_LENGTH = 1000;
    public const MAX_TRANSCRIPT_LENGTH = 20000;
    public const MAX_MEDIA = 4;
    public const MAX_LINKS = 30;
    public const MAX_LINK_TEXT_LENGTH = 240;
    public const MEDIA_TYPES = ['video', 'audio'];

    private const MIME_TYPE = '#^(video|audio)/[a-z0-9][a-z0-9.+-]{0,60}$#';

    /**
     * The material on one slide, normalised; only the keys that carry something.
     *
     * @param array<array-key, mixed> $slide
     *
     * @return array{imageUrl?: string, altText?: string, transcript?: string, media?: list<array{type: string, url: string, mimeType?: string, transcript?: string}>, links?: list<array{text: string, url: string}>}
     *
     * @throws InvalidOnboardingValue
     */
    public static function normalise(array $slide, string $where): array
    {
        $material = [];

        $imageUrl = self::url($slide['imageUrl'] ?? null, $where . ' image');
        if ($imageUrl !== null) {
            $material['imageUrl'] = $imageUrl;
        }
        $altText = self::text($slide['altText'] ?? null, $where . ' alt text', self::MAX_ALT_TEXT_LENGTH);
        if ($altText !== null) {
            $material['altText'] = $altText;
        }
        $transcript = self::text($slide['transcript'] ?? null, $where . ' transcript', self::MAX_TRANSCRIPT_LENGTH);
        if ($transcript !== null) {
            $material['transcript'] = $transcript;
        }
        $media = self::media($slide['media'] ?? null, $where);
        if ($media !== []) {
            $material['media'] = $media;
        }
        $links = self::links($slide['links'] ?? null, $where);
        if ($links !== []) {
            $material['links'] = $links;
        }

        return $material;
    }

    /**
     * @return list<array{type: string, url: string, mimeType?: string, transcript?: string}>
     */
    private static function media(mixed $media, string $where): array
    {
        if ($media === null) {
            return [];
        }
        if (!is_array($media) || !array_is_list($media)) {
            throw InvalidOnboardingValue::for($where . ' media', 'not a list');
        }
        if (count($media) > self::MAX_MEDIA) {
            throw InvalidOnboardingValue::for($where . ' media', sprintf('more than %d items', self::MAX_MEDIA));
        }

        $normalised = [];
        foreach ($media as $index => $item) {
            $itemWhere = sprintf('%s media %d', $where, $index + 1);
            if (!is_array($item)) {
                throw InvalidOnboardingValue::for($itemWhere, 'not an object');
            }
            $type = is_string($item['type'] ?? null) ? $item['type'] : '';
            if (!in_array($type, self::MEDIA_TYPES, true)) {
                throw InvalidOnboardingValue::for($itemWhere . ' type', ($type === '' ? '(missing)' : $type) . ' — video or audio');
            }
            $url = self::url($item['url'] ?? null, $itemWhere)
                ?? throw InvalidOnboardingValue::required($itemWhere . ' address', 'every video or audio item needs one.');
            $entry = ['type' => $type, 'url' => $url];

            $mimeType = is_string($item['mimeType'] ?? null) ? strtolower(trim($item['mimeType'])) : '';
            if ($mimeType !== '') {
                if (preg_match(self::MIME_TYPE, $mimeType) !== 1 || !str_starts_with($mimeType, $type . '/')) {
                    throw InvalidOnboardingValue::for($itemWhere . ' content type', $mimeType);
                }
                $entry['mimeType'] = $mimeType;
            }
            $transcript = self::text($item['transcript'] ?? null, $itemWhere . ' transcript', self::MAX_TRANSCRIPT_LENGTH);
            if ($transcript !== null) {
                $entry['transcript'] = $transcript;
            }
            $normalised[] = $entry;
        }

        return $normalised;
    }

    /**
     * @return list<array{text: string, url: string}>
     */
    private static function links(mixed $links, string $where): array
    {
        if ($links === null) {
            return [];
        }
        if (!is_array($links) || !array_is_list($links)) {
            throw InvalidOnboardingValue::for($where . ' links', 'not a list');
        }
        if (count($links) > self::MAX_LINKS) {
            throw InvalidOnboardingValue::for($where . ' links', sprintf('more than %d links', self::MAX_LINKS));
        }

        $normalised = [];
        foreach ($links as $index => $link) {
            $linkWhere = sprintf('%s link %d', $where, $index + 1);
            if (!is_array($link)) {
                throw InvalidOnboardingValue::for($linkWhere, 'not an object');
            }
            $url = self::url($link['url'] ?? null, $linkWhere)
                ?? throw InvalidOnboardingValue::required($linkWhere . ' address', 'every link needs one.');
            $text = self::text($link['text'] ?? null, $linkWhere . ' text', self::MAX_LINK_TEXT_LENGTH)
                ?? throw InvalidOnboardingValue::required($linkWhere . ' text', 'every link needs wording.');
            $normalised[] = ['text' => $text, 'url' => $url];
        }

        return $normalised;
    }

    private static function url(mixed $value, string $where): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw InvalidOnboardingValue::for($where . ' address', '(not text)');
        }
        $url = trim($value);
        if ($url === '') {
            return null;
        }
        $parts = parse_url($url);
        if (
            strlen($url) > self::MAX_URL_LENGTH
            || preg_match('/[\s<>"\\\\]/', $url) === 1
            || filter_var($url, FILTER_VALIDATE_URL) === false
            || !is_array($parts)
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || ($parts['host'] ?? '') === ''
            || isset($parts['user'])
        ) {
            throw InvalidOnboardingValue::for($where . ' address', mb_substr($url, 0, 120) . ' — use a full https:// address');
        }

        return $url;
    }

    private static function text(mixed $value, string $where, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw InvalidOnboardingValue::for($where, '(not text)');
        }
        $trimmed = trim($value);
        if (mb_strlen($trimmed) > $max) {
            throw InvalidOnboardingValue::for($where, sprintf('longer than %d characters', $max));
        }

        return $trimmed === '' ? null : $trimmed;
    }
}
