<?php

declare(strict_types=1);

namespace TriCab\Api\Onboarding\HandbookImport;

use InvalidArgumentException;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Onboarding\Application\DTO\Authoring\AuthoredContent;
use TriCab\Core\Onboarding\Application\DTO\Authoring\ContentItemRecord;
use TriCab\Core\Onboarding\Application\DTO\Authoring\ContentItemSummary;
use TriCab\Core\Onboarding\Application\Service\AuthorOnboardingContentService;
use TriCab\Core\Onboarding\Contract\OnboardingContentRepository;

/**
 * Puts the converted handbook policies onto the platform (handbook policy
 * import, Step 3) — through the authoring service, never SQL.
 *
 * The Phase 4 plan's rule: a seed script is not an audit trail. Every policy is
 * created, saved and published by {@see AuthorOnboardingContentService}, the
 * same calls HR's editor makes, so each gets its `content.*` rows in
 * `audit.OnboardingAdminEvents` attributed to a named person.
 *
 * ## Idempotent by slug
 *
 * {@see plan()} reads and writes nothing; {@see apply()} carries a plan out.
 * For each policy, by the source's slug (so Phase 7 can map acknowledgements
 * across by slug):
 *
 *  - **create** — no such policy: create it, save the wording, publish v1.
 *  - **unchanged** — the live version already says exactly this: nothing.
 *  - **change** — the live wording differs: a new draft with the new wording,
 *    published only with `publishChanges`. It never supersedes work in
 *    progress; that is an author's decision, made in the editor.
 *  - **blocked** — somebody has a draft open, or the policy was never
 *    published. The import will not write over an author's work.
 *
 * The title, summary, chapter and position are item edits and are brought in
 * line on create and change alike.
 */
final readonly class HandbookPolicyImporter
{
    public const CREATE = 'create';
    public const UNCHANGED = 'unchanged';
    public const CHANGE = 'change';
    public const BLOCKED = 'blocked';

    public const SCHEMA = 'tricab.onboarding.handbook-policy/1';

    public function __construct(
        private OnboardingContentRepository $content,
        private AuthorOnboardingContentService $authoring,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $records the converter's policies/<slug>.json, decoded
     *
     * @return list<array{slug: string, action: string, reason: string, itemChanges: list<string>, record: array<string, mixed>}>
     *
     * @throws InvalidArgumentException when a record is not the converter's output
     */
    public function plan(array $records): array
    {
        $existing = [];
        foreach ($this->content->listItems() as $summary) {
            if ($summary->itemType === AuthoredContent::TYPE_POLICY) {
                $existing[$summary->slug] = $summary;
            }
        }

        $plan = [];
        foreach ($records as $record) {
            $slug = self::slugOf($record);
            // Refuses a record the rules would refuse, before anything is written.
            $wanted = AuthoredContent::fromInput(AuthoredContent::TYPE_POLICY, self::contentOf($record), null);
            $summary = $existing[$slug] ?? null;

            if ($summary === null) {
                $plan[] = self::step($slug, self::CREATE, 'New policy: create, save the wording and publish version 1.', [], $record);
                continue;
            }

            $item = $this->content->findItem(AuthoredContent::TYPE_POLICY, $summary->publicId);
            $itemChanges = $item === null ? [] : self::itemChanges($item, $record);

            if ($summary->draftVersionPublicId !== null) {
                $plan[] = self::step($slug, self::BLOCKED, sprintf('Version %d is an open draft. Publish or discard it in the editor first.', $summary->draftVersionNumber ?? 0), $itemChanges, $record);
                continue;
            }
            $live = $item === null ? null : $this->content->latestPublishedVersion($item);
            if ($live === null) {
                $plan[] = self::step($slug, self::BLOCKED, 'The policy exists but has never been published.', $itemChanges, $record);
                continue;
            }

            $plan[] = $live->content->sameAs($wanted)
                ? self::step($slug, self::UNCHANGED, sprintf('Version %d already says this.', $live->versionNumber), $itemChanges, $record)
                : self::step($slug, self::CHANGE, sprintf('The wording differs from live version %d.', $live->versionNumber), $itemChanges, $record);
        }

        return $plan;
    }

    /**
     * @param list<array{slug: string, action: string, reason: string, itemChanges: list<string>, record: array<string, mixed>}> $plan
     *
     * @return list<array{slug: string, action: string, result: string, versionNumber: ?int}>
     */
    public function apply(array $plan, ActorContext $actor, bool $publishChanges): array
    {
        $results = [];
        foreach ($plan as $step) {
            $record = $step['record'];
            $slug = $step['slug'];

            if ($step['action'] === self::CREATE) {
                $detail = $this->authoring->createItem($actor, AuthoredContent::TYPE_POLICY, ['slug' => $slug] + self::itemBody($record));
                $draft = $detail->versions[0] ?? null;
                $version = $draft === null ? null : $this->content->findVersion($detail->item, $draft->publicId);
                if ($version === null) {
                    $results[] = ['slug' => $slug, 'action' => $step['action'], 'result' => 'failed: the new policy has no draft', 'versionNumber' => null];
                    continue;
                }
                $saved = $this->authoring->saveDraft($actor, AuthoredContent::TYPE_POLICY, $detail->item->publicId, $version->publicId, $version->rowVersion, self::contentOf($record));
                $published = $this->authoring->publish($actor, AuthoredContent::TYPE_POLICY, $detail->item->publicId, $saved->publicId, $saved->rowVersion, false);
                $results[] = ['slug' => $slug, 'action' => $step['action'], 'result' => 'created and published', 'versionNumber' => $published['version']->versionNumber];
                continue;
            }

            $item = $this->findBySlug($slug);
            if ($item !== null && $step['itemChanges'] !== [] && $step['action'] !== self::BLOCKED) {
                $item = $this->authoring->updateItem($actor, AuthoredContent::TYPE_POLICY, $item->publicId, $item->rowVersion, self::itemBody($record) + ['isActive' => $item->isActive])->item;
            }

            if ($step['action'] !== self::CHANGE || $item === null) {
                $results[] = ['slug' => $slug, 'action' => $step['action'], 'result' => $step['action'] === self::UNCHANGED && $step['itemChanges'] !== [] ? 'details updated' : 'nothing written', 'versionNumber' => null];
                continue;
            }

            $draft = $this->authoring->createDraft($actor, AuthoredContent::TYPE_POLICY, $item->publicId);
            $saved = $this->authoring->saveDraft($actor, AuthoredContent::TYPE_POLICY, $item->publicId, $draft->publicId, $draft->rowVersion, self::contentOf($record));
            if (!$publishChanges) {
                $results[] = ['slug' => $slug, 'action' => $step['action'], 'result' => 'saved as a draft, not published', 'versionNumber' => $saved->versionNumber];
                continue;
            }
            $published = $this->authoring->publish($actor, AuthoredContent::TYPE_POLICY, $item->publicId, $saved->publicId, $saved->rowVersion, false);
            $results[] = ['slug' => $slug, 'action' => $step['action'], 'result' => 'published', 'versionNumber' => $published['version']->versionNumber];
        }

        return $results;
    }

    private function findBySlug(string $slug): ?ContentItemRecord
    {
        $match = array_values(array_filter(
            $this->content->listItems(),
            static fn (ContentItemSummary $summary): bool => $summary->itemType === AuthoredContent::TYPE_POLICY && $summary->slug === $slug,
        ));

        return $match === [] ? null : $this->content->findItem(AuthoredContent::TYPE_POLICY, $match[0]->publicId);
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return list<string>
     */
    private static function itemChanges(ContentItemRecord $item, array $record): array
    {
        $body = self::itemBody($record);
        $changes = [];
        foreach (['title' => $item->title, 'summary' => $item->summary, 'chapterTitle' => $item->chapterTitle, 'sortOrder' => $item->sortOrder] as $field => $current) {
            if ($body[$field] !== $current) {
                $changes[] = $field;
            }
        }

        return $changes;
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return array{title: string, summary: ?string, chapterTitle: ?string, sortOrder: ?int}
     */
    private static function itemBody(array $record): array
    {
        $metadata = is_array($record['metadata'] ?? null) ? $record['metadata'] : [];
        $title = $record['title'] ?? null;
        if (!is_string($title) || trim($title) === '') {
            throw new InvalidArgumentException(sprintf('%s: no title.', self::slugOf($record)));
        }
        $summary = is_string($record['summary'] ?? null) && trim($record['summary']) !== '' ? trim($record['summary']) : null;
        $chapter = is_string($metadata['chapterTitle'] ?? null) && trim($metadata['chapterTitle']) !== '' ? trim($metadata['chapterTitle']) : null;

        return [
            'title' => trim($title),
            'summary' => $summary,
            'chapterTitle' => $chapter,
            'sortOrder' => is_int($metadata['sortOrder'] ?? null) ? $metadata['sortOrder'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>
     */
    private static function contentOf(array $record): array
    {
        $content = $record['content'] ?? null;
        if (!is_array($content)) {
            throw new InvalidArgumentException(sprintf('%s: no content.', self::slugOf($record)));
        }

        /** @var array<string, mixed> $content */
        return $content;
    }

    /**
     * @param array<string, mixed> $record
     */
    private static function slugOf(array $record): string
    {
        if (($record['schema'] ?? null) !== self::SCHEMA) {
            throw new InvalidArgumentException('Not a converted handbook policy (schema ' . self::SCHEMA . ').');
        }
        $slug = $record['slug'] ?? null;
        if (!is_string($slug) || $slug === '') {
            throw new InvalidArgumentException('A converted policy has no slug.');
        }

        return $slug;
    }

    /**
     * @param list<string>          $itemChanges
     * @param array<string, mixed>  $record
     *
     * @return array{slug: string, action: string, reason: string, itemChanges: list<string>, record: array<string, mixed>}
     */
    private static function step(string $slug, string $action, string $reason, array $itemChanges, array $record): array
    {
        return ['slug' => $slug, 'action' => $action, 'reason' => $reason, 'itemChanges' => $itemChanges, 'record' => $record];
    }
}
