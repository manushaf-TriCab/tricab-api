<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

/**
 * Decoded ValidationRulesJson plus display behaviour extras.
 */
final readonly class SpecificationValidationRules
{
    public function __construct(
        public ?string $regex = null,
        public ?int $minLength = null,
        public ?int $maxLength = null,
        public bool $unique = false,
        public ?string $placeholder = null,
        public ?string $helpText = null,
        public ?string $defaultValue = null,
        public ?string $validationMessage = null,
        public bool $isVisible = true,
        public bool $isAdminOnly = false,
        public bool $isPublicVisible = true,
        public bool $allowExport = true,
        public bool $allowImport = true,
    ) {
    }

    /** @param array<string, mixed>|null $data */
    public static function fromArray(?array $data): self
    {
        $data ??= [];

        return new self(
            regex: isset($data['regex']) && is_string($data['regex']) && $data['regex'] !== '' ? $data['regex'] : null,
            minLength: isset($data['minLength']) && is_numeric($data['minLength']) ? (int) $data['minLength'] : null,
            maxLength: isset($data['maxLength']) && is_numeric($data['maxLength']) ? (int) $data['maxLength'] : null,
            unique: (bool) ($data['unique'] ?? false),
            placeholder: isset($data['placeholder']) && is_string($data['placeholder']) ? $data['placeholder'] : null,
            helpText: isset($data['helpText']) && is_string($data['helpText']) ? $data['helpText'] : null,
            defaultValue: isset($data['defaultValue']) && (is_string($data['defaultValue']) || is_numeric($data['defaultValue']))
                ? (string) $data['defaultValue']
                : null,
            validationMessage: isset($data['validationMessage']) && is_string($data['validationMessage'])
                ? $data['validationMessage']
                : null,
            isVisible: array_key_exists('isVisible', $data) ? (bool) $data['isVisible'] : true,
            isAdminOnly: (bool) ($data['isAdminOnly'] ?? false),
            isPublicVisible: array_key_exists('isPublicVisible', $data) ? (bool) $data['isPublicVisible'] : true,
            allowExport: array_key_exists('allowExport', $data) ? (bool) $data['allowExport'] : true,
            allowImport: array_key_exists('allowImport', $data) ? (bool) $data['allowImport'] : true,
        );
    }

    public static function fromJson(?string $json): self
    {
        if ($json === null || trim($json) === '') {
            return new self();
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return new self();
        }

        return self::fromArray($decoded);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'regex' => $this->regex,
            'minLength' => $this->minLength,
            'maxLength' => $this->maxLength,
            'unique' => $this->unique,
            'placeholder' => $this->placeholder,
            'helpText' => $this->helpText,
            'defaultValue' => $this->defaultValue,
            'validationMessage' => $this->validationMessage,
            'isVisible' => $this->isVisible,
            'isAdminOnly' => $this->isAdminOnly,
            'isPublicVisible' => $this->isPublicVisible,
            'allowExport' => $this->allowExport,
            'allowImport' => $this->allowImport,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
    }
}
