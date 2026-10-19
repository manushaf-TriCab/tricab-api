<?php

declare(strict_types=1);

namespace TriCab\Core\Npr\Application\Service;

use RuntimeException;

/**
 * Port of the npr-portal's ValidationService: the answer rules in
 * `resources/npr/field-dictionary.json` (and docs/ANSWER_RULES.md).
 *
 * Drafts may leave answers out; only {@see self::validateSubmission()} (used by
 * the submit step in N2) demands an explicit answer for every applicable Sales
 * field. Messages are the source's, word for word.
 *
 * Answers are arrays: `{state, value?, reason?, documentIds?, selectionMode?,
 * basis?, confidence?, remarks?}`.
 */
final class NprValidationService
{
    private const MILESTONES = ['budget_quote_date', 'firm_quote_date', 'sample_date', 'tender_date', 'first_delivery_date'];

    /** @var list<array<string, mixed>> */
    private array $fields = [];

    /** @var array<string, array<string, mixed>> */
    private array $fieldsById = [];

    /**
     * @param array<string, mixed> $dictionary the decoded field dictionary
     */
    public function __construct(array $dictionary)
    {
        $fields = $dictionary['fields'] ?? null;
        if (!is_array($fields)) {
            throw new RuntimeException('The NPR field dictionary has no fields.');
        }
        foreach ($fields as $field) {
            if (!is_array($field) || !isset($field['id']) || !is_string($field['id'])) {
                throw new RuntimeException('The NPR field dictionary has a field without an id.');
            }
            /** @var array<string, mixed> $field */
            $this->fields[] = $field;
            $this->fieldsById[$field['id']] = $field;
        }
    }

    public static function fromFile(string $path): self
    {
        $raw = is_file($path) ? file_get_contents($path) : false;
        if ($raw === false) {
            throw new RuntimeException('The NPR field dictionary is missing: ' . $path);
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('The NPR field dictionary is not valid JSON.');
        }
        /** @var array<string, mixed> $decoded */

        return new self($decoded);
    }

    /** @return array<string, mixed>|null */
    public function getFieldDefinition(string $fieldId): ?array
    {
        return $this->fieldsById[$fieldId] ?? null;
    }

    /** @return list<array<string, mixed>> */
    public function getAllFields(): array
    {
        return $this->fields;
    }

    /**
     * The ids of the fields in one PDF section. Section 5 is the product
     * requirements CableFit reads.
     *
     * @return list<string>
     */
    public function fieldIdsInSection(int $section): array
    {
        $ids = [];
        foreach ($this->fields as $field) {
            if (($field['section'] ?? null) === $section) {
                $ids[] = self::str($field['id'] ?? '');
            }
        }

        return $ids;
    }

    /**
     * Whether a field applies, from its `appliesWhen` condition. A condition on
     * a variant-scoped field is read inside the same variant.
     *
     * @param array<string, mixed>      $fieldDef
     * @param array<string, mixed>      $requestAnswers
     * @param array<string, mixed>|null $variantAnswers
     */
    public function isFieldApplicable(array $fieldDef, array $requestAnswers, ?array $variantAnswers = null): bool
    {
        $condition = $fieldDef['appliesWhen'] ?? null;
        if (!is_array($condition)) {
            return true;
        }

        $op = self::str($condition['operator'] ?? '');
        if ($op === 'no_milestone_date_provided') {
            return !$this->hasMilestoneDate($requestAnswers);
        }

        $targetDef = $this->fieldsById[self::str($condition['field'] ?? '')] ?? null;
        if ($targetDef === null) {
            return false;
        }

        $context = (($targetDef['scope'] ?? '') === 'variant' && $variantAnswers !== null) ? $variantAnswers : $requestAnswers;
        $target = $context[self::str($condition['field'] ?? '')] ?? null;
        if (!is_array($target) || $target === []) {
            return false;
        }

        $state = self::str($target['state'] ?? '');
        if ($op === 'state_equals') {
            return $state === ($condition['value'] ?? '');
        }
        if ($state !== 'provided') {
            return false;
        }

        $value = $target['value'] ?? null;
        if ($op === 'equals') {
            return $value === ($condition['value'] ?? null);
        }
        if ($op === 'includes_any') {
            if (!is_array($value)) {
                return false;
            }
            $wanted = (array) ($condition['value'] ?? []);

            return array_intersect($value, $wanted) !== [];
        }

        return false;
    }

    /**
     * Validates one answer envelope against its field's definition.
     *
     * @param array<string, mixed>      $fieldDef
     * @param array<string, mixed>|null $answer
     * @return list<string>
     */
    public function validateAnswerEnvelope(array $fieldDef, ?array $answer): array
    {
        if ($answer === null) {
            return ['Answer is required'];
        }

        $errors = [];
        $state = self::str($answer['state'] ?? '');
        $allowed = $fieldDef['answerStates'] ?? [];
        if (!is_array($allowed) || !in_array($state, $allowed, true)) {
            return [sprintf("State '%s' is not allowed for field '%s'", $state, self::str($fieldDef['id'] ?? ''))];
        }

        if (in_array($state, ['not_stated', 'not_applicable'], true) && trim(self::str($answer['reason'] ?? '')) === '') {
            $errors[] = "A reason is required when answering '{$state}'";
        }

        if ($state === 'see_specification') {
            $documentIds = $answer['documentIds'] ?? [];
            if (!is_array($documentIds) || $documentIds === []) {
                $errors[] = "At least one attached document reference is required for 'see_specification'";
            }
        }

        if ($state === 'provided') {
            array_push($errors, ...$this->validateProvided($fieldDef, $answer));

            // Section 4 commercial answers carry a basis and a confidence.
            if (($fieldDef['section'] ?? 0) === 4 && ($fieldDef['basisConfidence'] ?? false)) {
                if (trim(self::str($answer['basis'] ?? '')) === '') {
                    $errors[] = 'Basis is required for commercial fields';
                }
                if (!in_array(self::str($answer['confidence'] ?? ''), ['confirmed', 'estimate', 'indicative'], true)) {
                    $errors[] = "Confidence must be 'confirmed', 'estimate', or 'indicative'";
                }
            }
        }

        return $errors;
    }

    /**
     * Whether the Sales part is ready to go to a manager (Sections 1-8): every
     * applicable Sales field answered and valid, at least one variant, and the
     * cross-field rules. Used by the submit step.
     *
     * @param array<string, mixed>       $requestAnswers
     * @param list<array<string, mixed>> $variants
     * @return array{valid: bool, errors: array<string, list<string>>}
     */
    public function validateSubmission(array $requestAnswers, array $variants): array
    {
        /** @var array<string, list<string>> $errors */
        $errors = [];

        if ($variants === []) {
            $errors['variants'] = ['At least one product variant is required in Section 5'];
        }

        foreach ($this->fields as $field) {
            if (($field['owner'] ?? '') !== 'sales' || ($field['scope'] ?? '') !== 'request') {
                continue;
            }
            if (!$this->isFieldApplicable($field, $requestAnswers)) {
                continue;
            }
            $this->checkRequired($errors, self::str($field['id'] ?? ''), $field, $requestAnswers[self::str($field['id'] ?? '')] ?? null, "Field '%s' requires an explicit answer");
        }

        foreach ($variants as $index => $variant) {
            $variantId = self::str($variant['id'] ?? 'variant_' . $index);
            $variantAnswers = is_array($variant['answers'] ?? null) ? $variant['answers'] : [];
            /** @var array<string, mixed> $variantAnswers */
            $label = self::str($variant['label'] ?? ($variant['name'] ?? $variantId));

            foreach ($this->fields as $field) {
                if (($field['owner'] ?? '') !== 'sales' || ($field['scope'] ?? '') !== 'variant') {
                    continue;
                }
                if (!$this->isFieldApplicable($field, $requestAnswers, $variantAnswers)) {
                    continue;
                }
                $fieldId = self::str($field['id'] ?? '');
                $this->checkRequired(
                    $errors,
                    $variantId . '.' . $fieldId,
                    $field,
                    $variantAnswers[$fieldId] ?? null,
                    "Variant '" . str_replace('%', '%%', $label) . "': '%s' requires an explicit answer",
                );
            }

            // Cross-field rule: armour_none_exclusive.
            $armour = $variantAnswers['armour'] ?? null;
            if (is_array($armour) && ($armour['state'] ?? '') === 'provided') {
                $values = (array) ($armour['value'] ?? []);
                if (in_array('none', $values, true) && count($values) > 1) {
                    $errors[$variantId . '.armour'][] = "'None' cannot coexist with other armour selections";
                }
            }
        }

        // Cross-field rule: verbal_only_exclusive.
        $documentTypes = $requestAnswers['document_types'] ?? null;
        if (is_array($documentTypes) && ($documentTypes['state'] ?? '') === 'provided') {
            $values = (array) ($documentTypes['value'] ?? []);
            if (in_array('verbal_only', $values, true) && count($values) > 1) {
                $errors['document_types'][] = "'Verbal only' cannot coexist with other document evidence choices";
            }
        }

        // Cross-field rule: commercial_timing.
        if (!$this->hasMilestoneDate($requestAnswers)) {
            $estimate = $requestAnswers['timing_estimate'] ?? null;
            if (!is_array($estimate) || ($estimate['state'] ?? '') !== 'provided' || empty($estimate['value'])) {
                $errors['first_delivery_date'][] = 'At least one milestone date must be provided, or a timing estimate with basis must be supplied';
            }
        }

        // Cross-field rule: sales_declaration_true.
        $declaration = $requestAnswers['sales_declaration'] ?? null;
        if (!is_array($declaration) || ($declaration['state'] ?? '') !== 'provided' || ($declaration['value'] ?? null) !== true) {
            $errors['sales_declaration'][] = 'Sales declaration must be confirmed before submission';
        }

        return ['valid' => $errors === [], 'errors' => $errors];
    }

    /**
     * Section 10 (TSD assessment) must be complete before screening is requested.
     *
     * @param array<string, mixed> $requestAnswers
     * @return array{valid: bool, errors: array<string, list<string>>}
     */
    public function validateForScreening(array $requestAnswers): array
    {
        /** @var array<string, list<string>> $errors */
        $errors = [];
        foreach ($this->fields as $field) {
            if (($field['owner'] ?? '') !== 'tsd' || ($field['section'] ?? 0) !== 10) {
                continue;
            }
            if (!$this->isFieldApplicable($field, $requestAnswers)) {
                continue;
            }
            $fieldId = self::str($field['id'] ?? '');
            $this->checkRequired($errors, $fieldId, $field, $requestAnswers[$fieldId] ?? null, "Section 10 field '%s' requires an explicit answer");
        }

        return ['valid' => $errors === [], 'errors' => $errors];
    }

    /**
     * @param array<string, list<string>> $errors
     * @param array<string, mixed>        $field
     */
    private function checkRequired(array &$errors, string $key, array $field, mixed $answer, string $missingFormat): void
    {
        if (!is_array($answer)) {
            $errors[$key] = [sprintf($missingFormat, self::str($field['label'] ?? ''))];

            return;
        }
        /** @var array<string, mixed> $answer */
        $found = $this->validateAnswerEnvelope($field, $answer);
        if ($found !== []) {
            $errors[$key] = $found;
        }
    }

    /** @param array<string, mixed> $requestAnswers */
    private function hasMilestoneDate(array $requestAnswers): bool
    {
        foreach (self::MILESTONES as $milestone) {
            $answer = $requestAnswers[$milestone] ?? null;
            if (is_array($answer) && ($answer['state'] ?? '') === 'provided' && !empty($answer['value'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The typed-value checks for a `provided` answer.
     *
     * @param array<string, mixed> $fieldDef
     * @param array<string, mixed> $answer
     * @return list<string>
     */
    private function validateProvided(array $fieldDef, array $answer): array
    {
        $errors = [];
        $value = $answer['value'] ?? null;
        $options = is_array($fieldDef['options'] ?? null) ? $fieldDef['options'] : [];

        switch (self::str($fieldDef['type'] ?? '')) {
            case 'text':
                if (!is_string($value) || trim($value) === '') {
                    $errors[] = 'Text value cannot be empty';
                }
                break;
            case 'number':
            case 'integer':
                if (!is_numeric($value)) {
                    $errors[] = 'Numeric value is required';
                }
                break;
            case 'boolean':
                if (!is_bool($value)) {
                    $errors[] = 'Boolean value is required';
                }
                break;
            case 'date':
                if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
                    $errors[] = 'Valid date (YYYY-MM-DD) is required';
                }
                break;
            case 'single_select':
                if (!in_array($value, $options, true)) {
                    $errors[] = 'Value must be one of: ' . implode(', ', array_map(self::str(...), $options));
                }
                break;
            case 'multi_select':
                if (!is_array($value) || $value === []) {
                    $errors[] = 'At least one option must be selected';
                    break;
                }
                $invalid = array_diff($value, $options);
                if ($invalid !== []) {
                    $errors[] = 'Invalid option(s): ' . implode(', ', array_map(self::str(...), $invalid));
                }
                $modes = is_array($fieldDef['selectionModes'] ?? null) ? $fieldDef['selectionModes'] : ['all_required'];
                if (!in_array(self::str($answer['selectionMode'] ?? ''), $modes, true)) {
                    $errors[] = 'Selection mode must be one of: ' . implode(', ', array_map(self::str(...), $modes));
                }
                break;
            case 'money':
                if (!is_array($value) || !isset($value['amount']) || !is_numeric($value['amount'])) {
                    $errors[] = 'Amount must be a numeric value';
                }
                break;
            case 'money_rate':
                if (!is_array($value) || !isset($value['amount']) || !is_numeric($value['amount'])) {
                    $errors[] = 'Amount must be a numeric value';
                }
                if (!is_array($value) || !in_array($value['per'] ?? null, ['m', 'km', 'drum'], true)) {
                    $errors[] = "Rate basis ('per') must be 'm', 'km', or 'drum'";
                }
                break;
            case 'text_list':
                if (!is_array($value) || $value === []) {
                    $errors[] = 'At least one text entry is required';
                }
                break;
            case 'core_groups':
                if (!is_array($value)) {
                    $errors[] = 'Core groups array is required';
                }
                break;
            case 'temperature_range':
                if (!is_array($value) || (empty($value['originalText']) && !isset($value['minimum_C']) && !isset($value['maximum_C']))) {
                    $errors[] = 'Temperature range requires originalText or minimum/maximum bounds';
                }
                break;
            case 'standard_references':
                if (!is_array($value) || $value === []) {
                    $errors[] = 'At least one standard reference is required';
                }
                break;
            case 'document_reference':
                if (!is_array($value) || empty($value['reference'])) {
                    $errors[] = 'Specification reference is required';
                }
                break;
            case 'attachments':
                if (!is_array($value)) {
                    $errors[] = 'Attachments list is required';
                }
                break;
            case 'voltage_rating':
                if (!is_array($value) || empty($value['originalNotation'])) {
                    $errors[] = 'Original voltage rating notation is required';
                }
                break;
        }

        return $errors;
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
