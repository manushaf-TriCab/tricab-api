<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\DTO;

use TriCab\Core\Onboarding\Domain\Service\FormPayloadSplitter;

/**
 * A submitted form after {@see FormPayloadSplitter} has taken it apart, in the
 * shape the two destinations need.
 *
 * It exists so that a repository signature says *split payload* rather than
 * `array $data`. A method taking the whole payload is one somebody can call with
 * an unsplit array, and the failure is silent: the bank account is written to the
 * workflow schema, everything works, and nobody notices until a `SELECT *` in a
 * report puts it on screen. Requiring this type means the splitter has run.
 *
 * {@see $payroll} and {@see $restricted} are kept apart rather than merged into
 * one "restricted" bag even though both land in `[onboarding_hr]`. They go to
 * different tables — `PayrollDetails` and `RestrictedPersonalDetails` — and a
 * merged bag would have to be re-classified on the way out, which is the
 * classification happening twice with two chances to disagree.
 */
final readonly class SplitFormPayload
{
    /**
     * @param array<string, mixed> $ordinary   dotted leaf paths, ordinary HR
     * @param array<string, mixed> $payroll    dotted leaf paths, C3
     * @param array<string, mixed> $restricted dotted leaf paths, C4
     */
    private function __construct(
        public array $ordinary,
        public array $payroll,
        public array $restricted,
    ) {
    }

    /**
     * @param array<string, mixed> $payload         the submitted body, nested or flat
     * @param array<string, int>   $classifications leaf path to DataClass value
     *
     * @throws \TriCab\Core\Onboarding\Domain\Exception\FormVersionNotClassified
     */
    public static function fromPayload(array $payload, array $classifications): self
    {
        $split = FormPayloadSplitter::split(
            FormPayloadSplitter::flatten($payload),
            $classifications,
        );

        return new self($split['ordinary'], $split['payroll'], $split['restricted']);
    }

    /** What `onboarding.FormSubmissions.HasRestrictedData` records. */
    public function hasRestrictedData(): bool
    {
        return $this->payroll !== [] || $this->restricted !== [];
    }
}
