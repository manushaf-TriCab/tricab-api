<?php

declare(strict_types=1);

namespace TriCab\Api\PlatformSettings;

use InvalidArgumentException;

/**
 * Administrator-managed deployment catalog; credentials never belong here.
 * @phpstan-type Model array{id:string,provider:string,capabilities:list<string>}
 * @phpstan-type Requirement array{provider:string,capabilities:list<string>}
 * @phpstan-type Metadata array{models:list<Model>,tasks:array<string,Requirement>}
 */
final class PlatformModelCatalog
{
    private const CAPABILITIES = ['text', 'vision', 'structured', 'decisions'];

    /** @param array<string,mixed> $defaults
     * @param list<array<string,mixed>>|null $models
     * @return Metadata
     */
    public static function metadata(array $defaults, ?array $models = null): array
    {
        $tasks = [];
        $seed = [];
        $ai = $defaults['ai'] ?? [];
        $defaultTasks = is_array($ai) ? ($ai['tasks'] ?? []) : [];
        if (!is_array($defaultTasks)) {
            throw new InvalidArgumentException('Invalid AI task defaults.');
        }
        foreach ($defaultTasks as $key => $model) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('Invalid AI task identifier.');
            }
            $requirements = self::requirements($key);
            $tasks[$key] = $requirements;
            if (!is_string($model) || trim($model) === '') {
                continue;
            }
            if (isset($seed[$model]) && $seed[$model]['provider'] !== $requirements['provider']) {
                // A shared identifier across transports is ambiguous: an admin must
                // register distinct deployment identifiers before changing this task.
                continue;
            }
            $seed[$model] = [
                'id' => $model,
                'provider' => $requirements['provider'],
                'capabilities' => array_values(array_unique([
                    ...($seed[$model]['capabilities'] ?? []), ...$requirements['capabilities'],
                ])),
            ];
        }
        return ['models' => self::validateModels($models ?? array_values($seed)), 'tasks' => $tasks];
    }

    /** @return array{provider:string,capabilities:list<string>} */
    private static function requirements(string $task): array
    {
        if ($task === 'rfqReaderModel') {
            return ['provider' => 'foundry', 'capabilities' => ['text', 'vision', 'structured']];
        }
        if ($task === 'rfqReaderNormalizerModel') {
            return ['provider' => 'foundry', 'capabilities' => ['text', 'structured']];
        }
        if (in_array($task, ['foundryModelDeployment', 'rfqMailClassifierModel', 'cablefitResearchModel'], true)) {
            return ['provider' => 'foundry', 'capabilities' => ['text']];
        }
        // The RFQ parser's own model calls moved from OpenRouter to Claude on Foundry (plan M7 O1/O2).
        if (in_array($task, ['openrouterModel', 'rfqNormalizerModel', 'rfqTableShapeModel'], true)) {
            return ['provider' => 'foundry', 'capabilities' => ['text', 'structured']];
        }
        if (in_array($task, ['visionOcrPrimaryModel', 'visionOcrEscalationModel'], true)) {
            return ['provider' => 'foundry', 'capabilities' => ['text', 'vision', 'structured']];
        }
        if ($task === 'ar_classify') {
            return ['provider' => 'openrouter', 'capabilities' => ['decisions']];
        }
        $capabilities = ['text'];
        if (in_array($task, ['rfqReaderModel', 'visionOcrPrimaryModel', 'visionOcrEscalationModel', 'ap_classify_extract', 'ap_extract', 'ar_remittance'], true)) {
            $capabilities[] = 'vision';
        }
        if ($task !== 'productFinderModel') {
            $capabilities[] = 'structured';
        }
        return ['provider' => 'openrouter', 'capabilities' => $capabilities];
    }

    /** @return list<array{id:string,provider:string,capabilities:list<string>}> */
    public static function validateModels(mixed $models): array
    {
        if (!is_array($models) || !array_is_list($models) || count($models) > 100) {
            throw new InvalidArgumentException('Models must be a list of at most 100 registered deployments.');
        }
        $seen = [];
        $result = [];
        foreach ($models as $model) {
            if (!is_array($model) || array_diff(array_keys($model), ['id', 'provider', 'capabilities']) !== []) {
                throw new InvalidArgumentException('Invalid model registration.');
            }
            $id = $model['id'] ?? null;
            $provider = $model['provider'] ?? null;
            $capabilities = $model['capabilities'] ?? null;
            if (!is_string($id) || strlen($id) > 120 || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/-]*$/D', $id) || isset($seen[$id])) {
                throw new InvalidArgumentException('Model identifiers must be valid and unique.');
            }
            if (!in_array($provider, ['foundry', 'openrouter'], true)) {
                throw new InvalidArgumentException('Unsupported model provider.');
            }
            if (!is_array($capabilities) || !array_is_list($capabilities) || $capabilities === []) {
                throw new InvalidArgumentException('Declare model capabilities.');
            }
            foreach ($capabilities as $capability) {
                if (!is_string($capability) || !in_array($capability, self::CAPABILITIES, true)) {
                    throw new InvalidArgumentException('Unknown model capability.');
                }
            }
            $seen[$id] = true;
            $result[] = ['id' => $id, 'provider' => $provider, 'capabilities' => array_values(array_unique($capabilities))];
        }
        return $result;
    }

    /** @param Metadata $metadata */
    public static function validateSelection(string $task, string $model, array $metadata): void
    {
        $requirements = $metadata['tasks'][$task] ?? null;
        foreach ($metadata['models'] as $entry) {
            if ($entry['id'] !== $model) {
                continue;
            }
            if ($requirements === null || $entry['provider'] !== $requirements['provider']
                || array_diff($requirements['capabilities'], $entry['capabilities']) !== []) {
                throw new InvalidArgumentException("The selected model is incompatible with {$task}.");
            }
            return;
        }
        throw new InvalidArgumentException("Register the selected model before assigning it to {$task}.");
    }
}
