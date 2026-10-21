<?php

declare(strict_types=1);

namespace TriCab\Api\PlatformSettings;

use InvalidArgumentException;
use TriCab\Api\Config\Settings;
use TriCab\Api\Bootstrap\Environment;

final class PlatformSettingsService
{
    public const PYTHON_TASK_ENV = [
        'rfqReaderModel' => 'RFQ_READER_MODEL',
        'rfqReaderNormalizerModel' => 'RFQ_READER_NORMALIZER_MODEL',
        'rfqNormalizerModel' => 'RFQ_NORMALIZER_MODEL',
        'rfqTableShapeModel' => 'RFQ_TABLE_SHAPE_MODEL',
        'openrouterModel' => 'OPENROUTER_MODEL',
        'visionOcrPrimaryModel' => 'VISION_OCR_PRIMARY_MODEL',
        'visionOcrEscalationModel' => 'VISION_OCR_ESCALATION_MODEL',
    ];
    private const FINANCE_PURPOSES = ['task_details', 'statement_areas', 'task_email', 'task_reminder', 'task_email_draft', 'ap_classify_extract', 'ap_extract', 'ap_vendor_email_draft', 'ap_reply_draft', 'ap_reply_suggest', 'ar_classify', 'ar_prose', 'ar_reply_draft', 'ar_remittance'];
    private const PHP_TASKS = ['foundryModelDeployment', 'rfqMailClassifierModel', 'cablefitResearchModel'];
    private const RECIPIENTS = [
        'financeMailTestRecipient', 'triexpenseEmailTestRecipient',
        'onboardingEmailTestRecipient', 'nprMailTestRecipient', 'nprDeputyCeoEmail',
        'nprTsdQueueEmail', 'nprScreeningSignatoryEmails',
        'postmarkFromEmail', 'rfqMailbox', 'rfqMailMailbox', 'customerContactAccountsMailbox',
        'financeArMailbox', 'financeApMailbox',
    ];

    public function __construct(
        private readonly PlatformSettingsRepository $repository,
        private readonly Settings $settings,
        private readonly ?PlatformSettingsRuntimeCache $runtimeCache = null,
    ) {}

    private static function pythonFallback(string $key): string
    {
        return match ($key) {
            'rfqReaderModel', 'rfqReaderNormalizerModel' => '',
            'visionOcrPrimaryModel' => 'qwen/qwen3-vl-30b-a3b-instruct',
            'visionOcrEscalationModel' => 'qwen/qwen3-vl-235b-a22b-instruct',
            default => 'openai/gpt-4o-mini',
        };
    }

    /** @return array<string,mixed> */
    public function defaults(): array
    {
        $tasks = [];
        foreach (self::PHP_TASKS as $key) {
            $tasks[$key] = $this->settings->$key;
        }
        foreach (self::PYTHON_TASK_ENV as $key => $env) {
            $value = Environment::value($env);
            $tasks[$key] = $value === null || trim($value) === '' ? self::pythonFallback($key) : trim($value);
        }
        // Match the sidecar's fallback chains, not just each variable's literal
        // code default. The translator inherits the Azure reader deployment.
        if (trim(Environment::value('RFQ_READER_NORMALIZER_MODEL') ?? '') === '') {
            $tasks['rfqReaderNormalizerModel'] = $tasks['rfqReaderModel'];
        }
        if (trim(Environment::value('RFQ_NORMALIZER_MODEL') ?? '') === '') {
            $tasks['rfqNormalizerModel'] = $tasks['openrouterModel'];
        }
        if (trim(Environment::value('RFQ_TABLE_SHAPE_MODEL') ?? '') === '') {
            $tasks['rfqTableShapeModel'] = $tasks['rfqNormalizerModel'];
        }
        $tasks['productFinderModel'] = Environment::value('OPENROUTER_MODEL', 'anthropic/claude-sonnet-4.5') ?: 'anthropic/claude-sonnet-4.5';
        foreach (self::FINANCE_PURPOSES as $purpose) {
            $tasks[$purpose] = $purpose === 'ar_classify' ? 'typesafe/jev-1.13' : 'google/gemini-2.5-flash';
        }
        $notifications = [];
        foreach (self::RECIPIENTS as $key) {
            $notifications[$key] = $this->settings->$key;
        }
        return ['ai' => ['defaultModel' => '', 'tasks' => $tasks], 'notifications' => $notifications];
    }

    /** @return array<string,mixed> */
    public function snapshot(): array
    {
        $record = $this->repository->current();
        $defaults = self::map($record['defaults'] ?? $this->defaults());
        if ($defaults === []) {
            $defaults = $this->defaults();
        }
        $overrides = self::map($record['overrides']);
        $aiOverrides = self::map($overrides['ai'] ?? []);
        $models = array_key_exists('models', $aiOverrides) ? PlatformModelCatalog::validateModels($aiOverrides['models']) : null;
        $metadata = PlatformModelCatalog::metadata($defaults, $models);
        $sections = self::merge($defaults, $overrides);
        $ai = self::map($sections['ai'] ?? []);
        $tasks = self::map($ai['tasks'] ?? []);
        $global = $ai['defaultModel'] ?? null;
        foreach (self::map($aiOverrides['tasks'] ?? []) as $task => $model) {
            if ($model === null) {
                $tasks[$task] = $global;
            }
        }
        $ai['tasks'] = $tasks;
        unset($ai['models']);
        $sections['ai'] = $ai;
        return [
            'version' => $record['version'],
            'environment' => $this->settings->appEnv,
            'metadata' => $metadata,
            'sections' => $sections,
            'defaults' => $defaults,
            'sources' => $this->sources($overrides),
            'overrides' => $overrides,
        ];
    }

    /** @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function sources(array $overrides): array
    {
        $aiOverrides = self::map($overrides['ai'] ?? []);
        $taskOverrides = self::map($aiOverrides['tasks'] ?? []);
        $notificationOverrides = self::map($overrides['notifications'] ?? []);
        $sources = ['ai' => ['defaultModel' => isset($aiOverrides['defaultModel']) ? 'override' : 'environment', 'tasks' => []], 'notifications' => []];
        $defaultsAi = self::map($this->defaults()['ai']);
        foreach (array_keys(self::map($defaultsAi['tasks'] ?? [])) as $task) {
            $sources['ai']['tasks'][$task] = array_key_exists($task, $taskOverrides) ? ($taskOverrides[$task] === null ? 'platform-default' : 'override') : 'environment';
        }
        foreach (self::RECIPIENTS as $key) {
            $sources['notifications'][$key] = isset($notificationOverrides[$key]) ? 'override' : 'environment';
        }
        return $sources;
    }

    /** @return array<string,mixed> */
    public function effective(): array
    {
        return self::map($this->snapshot()['sections']);
    }

    /** Only explicit choices are sent to sidecars; their own environment remains authoritative otherwise.
     * @return array<string,mixed>
     */
    public function runtimeOverrides(): array
    {
        $record = $this->repository->current();
        $overrides = self::map($record['overrides']);
        // Once edited, PHP settings retain the captured baseline even if a later
        // deployment changes its environment. Sidecars retain their own environment
        // unless a task is explicitly selected here.
        if ($record['version'] > 0 && isset($record['defaults']) && $record['defaults'] !== []) {
            $baseline = self::map($record['defaults']);
            $baselineAi = self::map($baseline['ai'] ?? []);
            $baselineTasks = self::map($baselineAi['tasks'] ?? []);
            $aiOverrides = self::map($overrides['ai'] ?? []);
            $taskOverrides = self::map($aiOverrides['tasks'] ?? []);
            $tasks = [];
            foreach (self::PHP_TASKS as $key) {
                $model = $baselineTasks[$key] ?? null;
                if (is_string($model) && $model !== '') {
                    $tasks[$key] = $model;
                }
            }
            // Explicit overrides win over the frozen baseline.
            $aiOverrides['tasks'] = array_replace($tasks, $taskOverrides);
            $overrides['ai'] = $aiOverrides;
            $overrides['notifications'] = array_replace(self::map($baseline['notifications'] ?? []), self::map($overrides['notifications'] ?? []));
        }
        $aiOverrides = self::map($overrides['ai'] ?? []);
        $global = $aiOverrides['defaultModel'] ?? null;
        $runtimeTasks = self::map($aiOverrides['tasks'] ?? []);
        foreach ($runtimeTasks as $task => $model) {
            if ($model === null) {
                if (!is_string($global) || $global === '') {
                    throw new InvalidArgumentException('An inherited task requires a platform default model.');
                }
                $runtimeTasks[$task] = $global;
            }
        }
        $aiOverrides['tasks'] = $runtimeTasks;
        $overrides['ai'] = $aiOverrides;
        return $overrides;
    }

    /** @return list<array<string,mixed>> */
    public function history(): array
    {
        return $this->repository->history();
    }

    /** @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    public function replace(int $expectedVersion, array $overrides, int $actorUserId): array
    {
        $this->validate($overrides);
        if (!$this->repository->replace($expectedVersion, $overrides, $this->defaults(), $actorUserId, 'update')) {
            throw new PlatformSettingsVersionConflict();
        }
        $this->publish();
        return $this->snapshot();
    }

    /** @return array<string,mixed> */
    public function restore(int $expectedVersion, int $version, int $actorUserId): array
    {
        foreach ($this->repository->history() as $event) {
            if ($event['version'] === $version) {
                $overrides = self::map($event['overrides']);
                $this->validate($overrides);
                if (!$this->repository->replace($expectedVersion, $overrides, $this->defaults(), $actorUserId, 'restore')) {
                    throw new PlatformSettingsVersionConflict();
                }
                $this->publish();
                return $this->snapshot();
            }
        }
        throw new InvalidArgumentException('Settings history version was not found.');
    }

    /** @return array<string,mixed> */
    public function reset(int $expectedVersion, ?string $section, int $actorUserId): array
    {
        if ($section !== null && !in_array($section, ['ai', 'notifications'], true)) {
            throw new InvalidArgumentException('section must be ai or notifications.');
        }
        $overrides = self::map($this->repository->current()['overrides']);
        if ($section === null) {
            $overrides = [];
        } else {
            unset($overrides[$section]);
        }
        if (!$this->repository->replace($expectedVersion, $overrides, $this->defaults(), $actorUserId, 'reset')) {
            throw new PlatformSettingsVersionConflict();
        }
        $this->publish();
        return $this->snapshot();
    }

    /** Puts a saved change in the runtime cache now, rather than when a reader next checks the database. */
    private function publish(): void
    {
        if ($this->runtimeCache === null) {
            return;
        }
        try {
            $this->runtimeCache->store($this->runtimeOverrides());
        } catch (\Throwable $exception) {
            // The change is saved. Drop the old copy so readers go back to the database.
            error_log('[platform-settings] updating the runtime cache failed (' . $exception::class . '); dropping the cached copy');
            try {
                $this->runtimeCache->forget();
            } catch (\Throwable) {
            }
        }
    }

    /** @param array<string,mixed> $overrides */
    private function validate(array $overrides): void
    {
        $record = $this->repository->current();
        $baseline = self::map($record['defaults'] ?? $this->defaults());
        if ($baseline === []) {
            $baseline = $this->defaults();
        }
        foreach ($overrides as $section => $values) {
            if (!in_array($section, ['ai', 'notifications'], true) || !is_array($values) || ($values !== [] && array_is_list($values))) {
                throw new InvalidArgumentException('Unknown or invalid settings section.');
            }
        }
        $ai = $overrides['ai'] ?? [];
        $models = array_key_exists('models', $ai) ? PlatformModelCatalog::validateModels($ai['models']) : null;
        $metadata = PlatformModelCatalog::metadata($baseline, $models);
        $global = $overrides['ai']['defaultModel'] ?? null;
        if ($global !== null && $global !== '') {
            $registered = false;
            foreach ($metadata['models'] as $entry) {
                if ($entry['id'] === $global) {
                    $registered = true;
                    break;
                }
            }
            if (!$registered) {
                throw new InvalidArgumentException('Register the platform default model first.');
            }
        }
        foreach ($overrides as $section => $values) {
            if ($section === 'ai') {
                foreach ($values as $key => $value) {
                    if ($key === 'defaultModel') {
                        $this->model($value);
                    } elseif ($key === 'models') {
                        PlatformModelCatalog::validateModels($value);
                    } elseif ($key === 'tasks' && is_array($value) && ($value === [] || !array_is_list($value))) {
                        foreach ($value as $task => $model) {
                            if (!in_array($task, [...self::PHP_TASKS, ...array_keys(self::PYTHON_TASK_ENV), ...self::FINANCE_PURPOSES, 'productFinderModel'], true)) {
                                throw new InvalidArgumentException('Unknown AI task.');
                            }
                            if ($model === null) {
                                if (!is_string($global) || $global === '') {
                                    throw new InvalidArgumentException('Choose a platform default model before inheriting it.');
                                }
                                PlatformModelCatalog::validateSelection($task, $global, $metadata);
                            } else {
                                if (!is_string($model)) {
                                    throw new InvalidArgumentException('Invalid model identifier.');
                                }
                                $this->model($model);
                                PlatformModelCatalog::validateSelection($task, $model, $metadata);
                            }
                        }
                    } else {
                        throw new InvalidArgumentException('Unknown AI setting.');
                    }
                }
            } else {
                foreach ($values as $key => $value) {
                    if (!in_array($key, self::RECIPIENTS, true)) {
                        throw new InvalidArgumentException('Unknown notification setting.');
                    }
                    if ($key === 'nprScreeningSignatoryEmails') {
                        if (!is_array($value) || !array_is_list($value) || count($value) > 20) {
                            throw new InvalidArgumentException('Signatory emails must be a list of at most 20 addresses.');
                        }
                        foreach ($value as $email) {
                            $this->email($email);
                        }
                    } elseif ($value !== '') {
                        $this->email($value);
                    }
                }
            }
        }
    }

    private function model(mixed $value): void
    {
        if (!is_string($value) || strlen($value) > 120 || ($value !== '' && !preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/-]*$/D', $value))) {
            throw new InvalidArgumentException('Invalid model identifier.');
        }
    }

    private function email(mixed $value): void
    {
        if (!is_string($value) || strlen($value) > 254 || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Invalid email address.');
        }
    }

    /** @return array<string,mixed> */
    private static function map(mixed $value): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidArgumentException('Settings section must be an object.');
        }
        $result = [];
        foreach ($value as $key => $entry) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('Settings key must be a string.');
            }
            $result[$key] = $entry;
        }
        return $result;
    }

    /** @param array<string,mixed> $defaults
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private static function merge(array $defaults, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            $defaults[$key] = is_array($value) && isset($defaults[$key]) && is_array($defaults[$key]) && !array_is_list($value)
                ? self::merge(self::map($defaults[$key]), self::map($value)) : $value;
        }
        return $defaults;
    }
}
