<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Migration\Form;

/**
 * One source form payload in the platform's field paths (Phase 7.2), split by
 * who fills each part: the trainee's answers (a submission or a draft), and the
 * HR and manager parts (`onboarding.FormContributions`).
 *
 * Every map is flat — `bank1.bsb => "062000"` — which is what
 * {@see \TriCab\Core\Onboarding\Domain\Service\FormPayloadSplitter} and the
 * submission rules take. Empty source values (`''`, null) are left out, as the
 * platform treats an absent answer as unanswered.
 *
 * `photos` are the vehicle report's condition photos, still as the source held
 * them (base64 JPEG); execution stores each one and writes its document id into
 * the HR part's `photos` answer.
 *
 * `dropped` names every non-empty source value that has no platform field, so
 * the preview can say what is left behind rather than lose it silently.
 */
final readonly class TranslatedForm
{
    /**
     * @param array<string, scalar> $trainee
     * @param array<string, scalar> $hr
     * @param array<string, scalar> $manager
     * @param list<array{sourceId: string, filename: string, base64: string}> $photos
     * @param list<string> $dropped source paths
     */
    public function __construct(
        public array $trainee,
        public array $hr = [],
        public array $manager = [],
        public array $photos = [],
        public array $dropped = [],
    ) {
    }
}
