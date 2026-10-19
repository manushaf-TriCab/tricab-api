<?php

declare(strict_types=1);

namespace TriCab\Core\CableFit\Application;

use Closure;
use DateTimeImmutable;
use TriCab\Core\CableFit\Domain\ResearchFinding;
use TriCab\Core\CableFit\Domain\ResearchModelFailed;
use TriCab\Core\CableFit\Domain\ResearchModelResponse;

/**
 * Turns the model's answer into findings. The answer is the JSON object in the final text (code fences and
 * lead-in text tolerated). A search result's `page_age`, matched by URL, beats the date the model states.
 * Items without a claim, a URL and a title are skipped; the source-domain check is the runner's.
 */
final class ResearchResponseParser
{
    private const MAX_FINDINGS = 30;

    /** @var Closure(): string */
    private Closure $idGenerator;

    /** @param Closure(): string $idGenerator */
    public function __construct(Closure $idGenerator)
    {
        $this->idGenerator = $idGenerator;
    }

    /**
     * @return list<ResearchFinding>
     *
     * @throws ResearchModelFailed when the answer is not the expected JSON, or has findings but none usable
     */
    public function parse(ResearchModelResponse $response): array
    {
        $decoded = $this->answer($response->content);
        $items = $decoded['findings'] ?? null;
        if (!is_array($items) || !array_is_list($items)) {
            throw new ResearchModelFailed('The model answered with JSON that has no findings list.');
        }

        $pageAges = $this->pageAges($response->content);
        $findings = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            /** @var array<string, mixed> $item */
            $finding = $this->finding($item, $pageAges, count($findings));
            if ($finding !== null) {
                $findings[] = $finding;
            }
            if (count($findings) >= self::MAX_FINDINGS) {
                break;
            }
        }
        if ($items !== [] && $findings === []) {
            throw new ResearchModelFailed('The model returned findings, but none had a claim, a URL and a title.');
        }

        return $findings;
    }

    /**
     * "March 11, 2026" => "2026-03-11"; "2026-03" and "2026" stay as they are. Null when the text is not a
     * date this can read (a relative age such as "2 days ago" included).
     */
    public static function normaliseDate(string $text): ?string
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[T ].*)?$/', $text, $m) === 1) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) && self::yearOk((int) $m[1]) ? sprintf('%s-%s-%s', $m[1], $m[2], $m[3]) : null;
        }
        if (preg_match('/^(\d{4})-(\d{2})$/', $text, $m) === 1) {
            return (int) $m[2] >= 1 && (int) $m[2] <= 12 && self::yearOk((int) $m[1]) ? $text : null;
        }
        if (preg_match('/^\d{4}$/', $text) === 1) {
            return self::yearOk((int) $text) ? $text : null;
        }
        foreach (['F j, Y' => 'Y-m-d', 'M j, Y' => 'Y-m-d', 'j F Y' => 'Y-m-d', 'j M Y' => 'Y-m-d', 'F Y' => 'Y-m', 'M Y' => 'Y-m'] as $in => $out) {
            $date = DateTimeImmutable::createFromFormat('!' . $in, $text, new \DateTimeZone('UTC'));
            $errors = DateTimeImmutable::getLastErrors();
            if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0)) && self::yearOk((int) $date->format('Y'))) {
                return $date->format($out);
            }
        }

        return null;
    }

    private static function yearOk(int $year): bool
    {
        return $year >= 1900 && $year <= 2100;
    }

    /**
     * @param list<array<string, mixed>> $content
     *
     * @return array<array-key, mixed>
     */
    private function answer(array $content): array
    {
        $lastResult = -1;
        foreach ($content as $i => $block) {
            if (($block['type'] ?? null) === 'web_search_tool_result') {
                $lastResult = $i;
            }
        }
        $after = '';
        $all = '';
        foreach ($content as $i => $block) {
            if (($block['type'] ?? null) !== 'text' || !is_string($block['text'] ?? null)) {
                continue;
            }
            $all .= $block['text'];
            if ($i > $lastResult) {
                $after .= $block['text'];
            }
        }
        if (trim($all) === '') {
            throw new ResearchModelFailed('The model returned no text answer.');
        }
        foreach (array_unique([$after, $all]) as $text) {
            $decoded = $this->json($text);
            if ($decoded !== null) {
                return $decoded;
            }
        }

        throw new ResearchModelFailed('The model answered with something that is not valid JSON.');
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function json(string $text): ?array
    {
        $candidates = [trim($text)];
        if (preg_match_all('/```[a-zA-Z]*\s*(.*?)```/s', $text, $fences) > 0) {
            foreach ($fences[1] as $fenced) {
                $candidates[] = trim($fenced);
            }
        }
        $open = strpos($text, '{');
        $close = strrpos($text, '}');
        if ($open !== false && $close !== false && $close > $open) {
            $candidates[] = substr($text, $open, $close - $open + 1);
        }
        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }
            $decoded = json_decode($candidate, true);
            if (is_array($decoded) && $decoded !== []) {
                // A bare list of findings is accepted as the findings.
                return array_is_list($decoded) ? ['findings' => $decoded] : $decoded;
            }
            if (is_array($decoded) && $decoded === [] && str_contains($candidate, 'findings')) {
                return ['findings' => []];
            }
        }

        return null;
    }

    /**
     * Search results' page_age by URL key.
     *
     * @param list<array<string, mixed>> $content
     *
     * @return array<string, string>
     */
    private function pageAges(array $content): array
    {
        $ages = [];
        foreach ($content as $block) {
            $results = $block['content'] ?? null;
            if (($block['type'] ?? null) !== 'web_search_tool_result' || !is_array($results)) {
                continue;
            }
            foreach ($results as $result) {
                if (!is_array($result) || !is_string($result['url'] ?? null) || !is_string($result['page_age'] ?? null)) {
                    continue;
                }
                $key = ResearchSourcePolicy::key($result['url']);
                if ($key !== null && !isset($ages[$key])) {
                    $ages[$key] = $result['page_age'];
                }
            }
        }

        return $ages;
    }

    /**
     * @param array<string, mixed>  $item
     * @param array<string, string> $pageAges
     */
    private function finding(array $item, array $pageAges, int $order): ?ResearchFinding
    {
        $claim = $this->text($item['claim'] ?? null, 2000);
        $url = $this->text($item['url'] ?? null, 2000);
        $title = $this->text($item['title'] ?? null, 500);
        if ($claim === null || $url === null || $title === null) {
            return null;
        }

        $publishedOn = null;
        $basis = ResearchFinding::BASIS_UNDATED;
        $key = ResearchSourcePolicy::key($url);
        if ($key !== null && isset($pageAges[$key])) {
            $publishedOn = self::normaliseDate($pageAges[$key]);
            if ($publishedOn !== null) {
                $basis = ResearchFinding::BASIS_PAGE_AGE;
            }
        }
        if ($publishedOn === null && ($item['dateBasis'] ?? null) !== ResearchFinding::BASIS_UNDATED && is_string($item['publishedOn'] ?? null)) {
            $publishedOn = self::normaliseDate($item['publishedOn']);
            if ($publishedOn !== null) {
                $basis = ResearchFinding::BASIS_STATED;
            }
        }

        $confidence = is_string($item['confidence'] ?? null) ? strtolower(trim($item['confidence'])) : '';
        if (!in_array($confidence, [ResearchFinding::CONFIDENCE_HIGH, ResearchFinding::CONFIDENCE_MEDIUM, ResearchFinding::CONFIDENCE_LOW], true)) {
            $confidence = ResearchFinding::CONFIDENCE_LOW;
        }

        return new ResearchFinding(
            ($this->idGenerator)(),
            $this->text($item['parameter'] ?? null, 200) ?? 'general',
            $claim,
            $this->text($item['relevance'] ?? null, 2000) ?? '',
            $url,
            $title,
            $this->text($item['publisher'] ?? null, 200),
            $publishedOn,
            $basis,
            $confidence,
            $order,
        );
    }

    private function text(mixed $value, int $max): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $text = trim($value);
        if ($text === '' || strtolower($text) === 'null') {
            return null;
        }

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max) : $text;
    }
}
