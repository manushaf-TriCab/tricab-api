<?php

declare(strict_types=1);

namespace TriCab\Api\Onboarding\FormImport;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Onboarding\Application\DTO\Authoring\AuthoredContent;
use TriCab\Core\Onboarding\Application\Service\AuthorOnboardingContentService;
use TriCab\Core\Onboarding\Contract\OnboardingContentRepository;

/**
 * Puts TriCab's own forms onto the platform (5.4) through the authoring service,
 * as the handbook policy import does, so each has its `content.*` audit rows in
 * a named person's name.
 *
 * **Drafts, never published.** HR opens each in the editor, reads it, and
 * publishes it; a form that collects bank accounts is not something an import
 * should put in front of new starters on its own.
 *
 * Idempotent by slug: a form that already exists is never touched — not its
 * draft, not its published versions. Changes after the first import are made
 * in the editor.
 */
final readonly class TriCabFormImporter
{
    public const CREATE = 'create';
    public const EXISTS = 'exists';

    public function __construct(
        private OnboardingContentRepository $content,
        private AuthorOnboardingContentService $authoring,
    ) {
    }

    /**
     * @return list<array{slug: string, title: string, accessGroup: string, fields: list<array<string, mixed>>}>
     */
    public static function definitions(): array
    {
        return [[
            'slug' => EmployeeDetailsFormDefinition::SLUG,
            'title' => EmployeeDetailsFormDefinition::TITLE,
            'accessGroup' => EmployeeDetailsFormDefinition::ACCESS_GROUP,
            'fields' => EmployeeDetailsFormDefinition::fields(),
        ]];
    }

    /**
     * @return list<array{slug: string, action: string, reason: string}>
     */
    public function plan(): array
    {
        $existing = [];
        foreach ($this->content->listItems() as $summary) {
            if ($summary->itemType === AuthoredContent::TYPE_FORM) {
                $existing[$summary->slug] = true;
            }
        }

        $plan = [];
        foreach (self::definitions() as $definition) {
            $plan[] = isset($existing[$definition['slug']])
                ? ['slug' => $definition['slug'], 'action' => self::EXISTS, 'reason' => 'Already on the platform; left exactly as it is.']
                : ['slug' => $definition['slug'], 'action' => self::CREATE, 'reason' => sprintf('New %s form with %d fields, saved as a draft for HR to publish.', $definition['accessGroup'], count($definition['fields']))];
        }

        return $plan;
    }

    /**
     * @param list<array{slug: string, action: string, reason: string}> $plan
     * @return list<array{slug: string, result: string}>
     */
    public function apply(array $plan, ActorContext $actor): array
    {
        $bySlug = array_column(self::definitions(), null, 'slug');
        $results = [];
        foreach ($plan as $step) {
            if ($step['action'] !== self::CREATE) {
                $results[] = ['slug' => $step['slug'], 'result' => 'not touched'];
                continue;
            }
            $definition = $bySlug[$step['slug']];
            $detail = $this->authoring->createItem($actor, AuthoredContent::TYPE_FORM, [
                'slug' => $definition['slug'],
                'title' => $definition['title'],
                'accessGroup' => $definition['accessGroup'],
            ]);
            $draft = $detail->versions[0] ?? null;
            $version = $draft === null ? null : $this->content->findVersion($detail->item, $draft->publicId);
            if ($version === null) {
                $results[] = ['slug' => $step['slug'], 'result' => 'failed: the new form has no draft'];
                continue;
            }
            $this->authoring->saveDraft($actor, AuthoredContent::TYPE_FORM, $detail->item->publicId, $version->publicId, $version->rowVersion, ['fields' => $definition['fields']]);
            $results[] = ['slug' => $step['slug'], 'result' => 'created; draft saved, not published'];
        }

        return $results;
    }
}
