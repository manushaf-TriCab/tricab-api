<?php

declare(strict_types=1);

namespace TriCab\Api\Onboarding\TrainingImport;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Onboarding\Application\DTO\Authoring\AuthoredContent;
use TriCab\Core\Onboarding\Application\Service\AuthorOnboardingContentService;
use TriCab\Core\Onboarding\Contract\OnboardingContentRepository;

/**
 * Puts the source's three TriCab training modules onto the platform through the
 * authoring service, so each has its `content.*` audit rows in a named
 * person's name — the same rule as the policy and form imports.
 *
 * **Drafts, never published.** The answer keys are HR-approved in the source,
 * but a module that decides whether somebody passed their induction is put in
 * front of new starters by HR, from the editor, not by an import.
 *
 * Idempotent by slug: a module that already exists is never touched.
 *
 * @phpstan-import-type TrainingRecord from SourceTrainingConverter
 */
final readonly class TriCabTrainingImporter
{
    public const CREATE = 'create';
    public const EXISTS = 'exists';

    public function __construct(
        private OnboardingContentRepository $content,
        private AuthorOnboardingContentService $authoring,
    ) {
    }

    /**
     * @param list<TrainingRecord> $records {@see SourceTrainingConverter::convert()}
     * @return list<array{slug: string, action: string, reason: string, record: TrainingRecord}>
     */
    public function plan(array $records): array
    {
        $existing = [];
        foreach ($this->content->listItems() as $summary) {
            if ($summary->itemType === AuthoredContent::TYPE_TRAINING) {
                $existing[$summary->slug] = true;
            }
        }

        $plan = [];
        foreach ($records as $record) {
            $slug = $record['slug'];
            $content = $record['content'];
            $plan[] = [
                'slug' => $slug,
                'action' => isset($existing[$slug]) ? self::EXISTS : self::CREATE,
                'reason' => isset($existing[$slug])
                    ? 'Already on the platform; left exactly as it is.'
                    : sprintf(
                        '%s: %d slides, %d questions, pass mark %d%%, saved as a draft for HR to publish.',
                        $record['documentReference'],
                        count($content['slides']),
                        count($content['questions']),
                        $content['passMarkPercent'],
                    ),
                'record' => $record,
            ];
        }

        return $plan;
    }

    /**
     * @param list<array{slug: string, action: string, reason: string, record: TrainingRecord}> $plan
     * @return list<array{slug: string, result: string}>
     */
    public function apply(array $plan, ActorContext $actor): array
    {
        $results = [];
        foreach ($plan as $step) {
            if ($step['action'] !== self::CREATE) {
                $results[] = ['slug' => $step['slug'], 'result' => 'not touched'];
                continue;
            }
            $record = $step['record'];
            $detail = $this->authoring->createItem($actor, AuthoredContent::TYPE_TRAINING, [
                'slug' => $step['slug'],
                'title' => $record['title'],
                'summary' => $record['summary'],
            ]);
            $draft = $detail->versions[0] ?? null;
            $version = $draft === null ? null : $this->content->findVersion($detail->item, $draft->publicId);
            if ($version === null) {
                $results[] = ['slug' => $step['slug'], 'result' => 'failed: the new module has no draft'];
                continue;
            }
            $this->authoring->saveDraft($actor, AuthoredContent::TYPE_TRAINING, $detail->item->publicId, $version->publicId, $version->rowVersion, $record['content']);
            $results[] = ['slug' => $step['slug'], 'result' => 'created; draft saved, not published'];
        }

        return $results;
    }
}
