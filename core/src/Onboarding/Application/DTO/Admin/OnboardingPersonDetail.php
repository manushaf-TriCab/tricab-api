<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\DTO\Admin;

/**
 * One person as the allocation screen needs them: who they are, what they hold,
 * what could be given to them, and the token a save must present.
 */
final readonly class OnboardingPersonDetail
{
    /**
     * @param list<CurrentAllocation> $allocations
     * @param list<AllocatableItem> $catalogue
     */
    public function __construct(
        public OnboardingPersonSummary $person,
        public string $rowVersion,
        public array $allocations,
        public array $catalogue,
        /** Null when the reader did not ask the directory; the console treats that as unknown. */
        public ?bool $canSignIn = null,
        /**
         * What is recorded against them, by kind; empty means the record can be
         * deleted. Null when the reader did not ask.
         *
         * @var array<string, int>|null
         */
        public ?array $recordedWork = null,
    ) {
    }

    public function withCanSignIn(bool $canSignIn): self
    {
        return new self($this->person, $this->rowVersion, $this->allocations, $this->catalogue, $canSignIn, $this->recordedWork);
    }

    /**
     * @param array<string, int> $recordedWork
     */
    public function withRecordedWork(array $recordedWork): self
    {
        return new self($this->person, $this->rowVersion, $this->allocations, $this->catalogue, $this->canSignIn, $recordedWork);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'person' => $this->person->toArray(),
            'rowVersion' => $this->rowVersion,
            'allocations' => array_map(static fn (CurrentAllocation $a) => $a->toArray(), $this->allocations),
            'catalogue' => array_map(static fn (AllocatableItem $i) => $i->toArray(), $this->catalogue),
            'canSignIn' => $this->canSignIn,
            'recordedWork' => $this->recordedWork,
            'canDelete' => $this->recordedWork === null ? null : $this->recordedWork === [],
        ];
    }
}
