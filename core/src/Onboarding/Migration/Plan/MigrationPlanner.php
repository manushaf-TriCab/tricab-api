<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Migration\Plan;

use TriCab\Core\Identity\Domain\Exception\InvalidIdentityValue;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Onboarding\Domain\Exception\InvalidOnboardingValue;
use TriCab\Core\Onboarding\Domain\Service\AssessmentGrader;
use TriCab\Core\Onboarding\Domain\Service\FormSchemaRules;
use TriCab\Core\Onboarding\Domain\Service\FormSubmissionRules;
use TriCab\Core\Onboarding\Domain\ValueObject\PolicyDeclaration;
use TriCab\Core\Onboarding\Domain\ValueObject\SignatureImage;
use TriCab\Core\Onboarding\Migration\ContentMap;
use TriCab\Core\Onboarding\Migration\Form\SourceFormTranslator;
use TriCab\Core\Onboarding\Migration\Source\SourceSnapshot;
use TriCab\Core\Onboarding\Migration\Target\TargetCatalogue;
use TriCab\Core\Onboarding\Migration\Target\TargetState;

/**
 * Turns a source snapshot into a migration plan (Phase 7.3): every person and
 * every record matched against the platform, with one decision each and every
 * finding named.
 *
 * ## Refuse the record, not the batch
 *
 * A record that cannot be migrated faithfully — a module the platform does not
 * have, an acknowledgement of a policy wording since replaced, an assignment
 * the person already holds — is **skipped with a reason**, and the rest of that
 * person's history still goes. The operator approves the plan with its skips in
 * view; nothing is skipped silently.
 *
 * Within a record the rule is the same one level down: slide progress naming
 * slides the platform deck does not have is left out and the assignment kept;
 * attempts sat against questions that are not the platform's are left out.
 * Nothing is guessed.
 *
 * ## What is carried as the source recorded it
 *
 * Statuses, dates, scores and pass flags are evidence, so they are carried as
 * recorded — never recomputed. Where the platform would have decided
 * differently (its pass decision is exact integer arithmetic; the source
 * compared a rounded score) the plan says so as a warning.
 *
 * Pure: no database, no request, no clock.
 */
final class MigrationPlanner
{
    public const TRAINING = 'training';
    public const POLICY = 'policy';
    public const FORM = 'form';

    private SourceSnapshot $snapshot;
    private TargetCatalogue $catalogue;
    private TargetState $state;
    private Findings $findings;
    /** @var array<int, string> source trainee id => lower-case email */
    private array $emails = [];

    public function plan(SourceSnapshot $snapshot, TargetCatalogue $catalogue, TargetState $state): MigrationPlan
    {
        $this->snapshot = $snapshot;
        $this->catalogue = $catalogue;
        $this->state = $state;
        $this->findings = new Findings();
        $this->emails = [];
        foreach ($snapshot->rows('trainees') as $trainee) {
            $this->emails[self::int($trainee['id'])] = strtolower(trim(self::str($trainee['email'])));
        }

        $people = [];
        foreach ($snapshot->rows('trainees') as $trainee) {
            $people[] = $this->person($trainee);
        }

        return new MigrationPlan($snapshot->hash(), $snapshot->isSynthetic(), $people, $this->findings->all(), self::sourceCounts($snapshot), $catalogue->fingerprintPart(), $state->fingerprintPart());
    }

    // ---------------------------------------------------------------- people

    /**
     * @param array<string, mixed> $trainee
     *
     * @return array<string, mixed>
     */
    private function person(array $trainee): array
    {
        $sourceId = self::int($trainee['id']);
        $subject = 'trainees#' . $sourceId;
        $email = $this->emails[$sourceId];
        $name = trim(self::str($trainee['full_name']));

        $items = [...$this->trainingItems($sourceId), ...$this->policyItems($sourceId), ...$this->formItems($sourceId)];
        $isAdmin = $trainee['is_admin'] === true || in_array($email, $this->snapshot->adminEmails(), true);

        $base = [
            'sourceId' => $sourceId,
            'email' => $email,
            'displayName' => $name,
            'profile' => [
                'position' => self::nullable($trainee['position']),
                'department' => self::nullable($trainee['department']),
                'branch' => self::nullable($trainee['branch']),
                'startDate' => $trainee['start_date'],
            ],
            'items' => $items,
        ];

        try {
            EmailAddress::fromString($email);
        } catch (InvalidIdentityValue) {
            $this->findings->blocking('INVALID_EMAIL', $subject, sprintf('"%s" is not an email address; this person and their %d records are not migrated.', $email, count($items)));

            return ['action' => MigrationPlan::PERSON_BLOCKED, 'userId' => null, 'profileAction' => null, 'departed' => false] + self::skipAll($base, 'INVALID_EMAIL');
        }

        if ($isAdmin && $items === []) {
            $this->findings->info('ADMIN_SKIPPED', $subject, $email . ' is a source admin with no induction of their own; not migrated as a person.');

            return ['action' => MigrationPlan::PERSON_SKIPPED_ADMIN, 'userId' => null, 'profileAction' => null, 'departed' => false] + $base;
        }
        if ($isAdmin) {
            $this->findings->warning('ADMIN_WITH_WORK', $subject, $email . ' is a source admin who also has induction work; migrated as a person.');
        }
        if ($trainee['profile_photo_sha256'] !== null) {
            $this->findings->info('PROFILE_PHOTO_NOT_CARRIED', $subject, 'Has a profile photo; the platform has nowhere to put it, so it stays in the source.');
        }

        $account = $this->state->account($email);
        if ($account === null) {
            if ($name === '') {
                $this->findings->blocking('NO_NAME_FOR_ACCOUNT', $subject, $email . ' has no platform account and no name to create one with.');

                return ['action' => MigrationPlan::PERSON_BLOCKED, 'userId' => null, 'profileAction' => null, 'departed' => false] + self::skipAll($base, 'NO_NAME_FOR_ACCOUNT');
            }
            $this->findings->info('ACCOUNT_CREATED', $subject, $email . ' has no platform account; a GUEST account will be created (owner decision, 25 Sep 2026). No email is sent.');

            return ['action' => MigrationPlan::PERSON_CREATE_ACCOUNT, 'userId' => null, 'profileAction' => MigrationPlan::PROFILE_CREATE, 'departed' => false] + $base;
        }

        $profile = $this->state->profile($account['userId']);
        if ($profile !== null) {
            $this->findings->info('EXISTING_RECORD', $subject, $email . ' already has an induction record; migrated work is added to it.');
            if ($profile['departed']) {
                $this->findings->warning('PERSON_HAS_LEFT', $subject, $email . ' has left; their history is migrated as evidence.');
            }
        }
        if (!$account['canSignIn']) {
            $this->findings->warning('CANNOT_SIGN_IN', $subject, $email . '\'s platform account cannot sign in yet (activation is a User Management decision).');
        }

        return [
            'action' => MigrationPlan::PERSON_LINK,
            'userId' => $account['userId'],
            'profileAction' => $profile === null ? MigrationPlan::PROFILE_CREATE : MigrationPlan::PROFILE_EXISTING,
            'departed' => $profile !== null && $profile['departed'],
        ] + $this->withOverlaps($base, $account['userId']);
    }

    /**
     * An assignment the person already holds on the platform is skipped, not
     * doubled; an acknowledgement already on the platform likewise.
     *
     * @param array<string, mixed> $person
     *
     * @return array<string, mixed>
     */
    private function withOverlaps(array $person, int $userId): array
    {
        /** @var list<array<string, mixed>> $items */
        $items = $person['items'];
        foreach ($items as $i => $item) {
            if ($item['action'] !== MigrationPlan::ITEM_CREATE) {
                continue;
            }
            $subject = self::str($item['sourceTable']) . '#' . self::int($item['sourceId']);
            if ($this->state->hasLiveAssignment($userId, self::str($item['kind']), self::str($item['slug']))) {
                $this->findings->warning('ALREADY_ASSIGNED', $subject, sprintf('%s already has %s "%s" on the platform; the source record is not migrated.', $person['email'], $item['kind'], $item['slug']));
                $items[$i] = self::skip($item, 'ALREADY_ASSIGNED');
            } elseif ($item['kind'] === self::POLICY && is_array($item['acknowledgement'] ?? null) && $this->state->hasAcknowledged($userId, self::str($item['slug']))) {
                $this->findings->warning('ALREADY_ACKNOWLEDGED', $subject, sprintf('%s has already acknowledged "%s" on the platform.', $person['email'], $item['slug']));
                $items[$i] = self::skip($item, 'ALREADY_ACKNOWLEDGED');
            }
        }
        $person['items'] = $items;

        return $person;
    }

    // -------------------------------------------------------------- training

    /** @return list<array<string, mixed>> */
    private function trainingItems(int $traineeId): array
    {
        $items = [];
        foreach ($this->snapshot->rows('assignments') as $row) {
            if ($row['trainee_id'] !== $traineeId) {
                continue;
            }
            $id = self::int($row['id']);
            $subject = 'assignments#' . $id;
            $module = $this->snapshot->row('modules', self::int($row['module_id']));
            $base = self::itemBase(self::TRAINING, 'assignments', $id, $row);
            if ($this->state->isMigrated('assignments', $id)) {
                $items[] = self::skip($base + ['slug' => null], 'ALREADY_MIGRATED');
                continue;
            }
            if ($module === null) {
                $this->findings->blocking('DANGLING_REFERENCE', $subject, 'names a module that is not in the snapshot.');
                $items[] = self::skip($base + ['slug' => null], 'DANGLING_REFERENCE');
                continue;
            }
            $reference = self::str($module['document_reference']);
            $slug = ContentMap::moduleSlug($reference, $this->snapshot->isSynthetic());
            $target = $slug === null ? null : $this->catalogue->module($slug);
            if ($slug === null || $target === null) {
                $this->findings->warning($slug === null ? 'NO_PLATFORM_MODULE' : 'MODULE_NOT_PUBLISHED', $subject, sprintf(
                    'Module "%s" (%s) %s; this assignment is not migrated.',
                    self::str($module['title']),
                    $reference === '' ? 'no document reference' : $reference,
                    $slug === null ? 'has no platform counterpart' : 'has no published platform version',
                ));
                $items[] = self::skip($base + ['slug' => $slug], $slug === null ? 'NO_PLATFORM_MODULE' : 'MODULE_NOT_PUBLISHED');
                continue;
            }

            $item = $base + [
                'slug' => $slug,
                'versionId' => $target['versionId'],
                'action' => MigrationPlan::ITEM_CREATE,
                'reason' => null,
                'progress' => $this->progress($id, $target['slideIds']),
                'attempts' => $this->attempts($traineeId, self::int($row['module_id']), $target),
            ];
            $this->checkTrainingEvidence($item, $target, $subject);
            $items[] = $item;
        }

        return $items;
    }

    /**
     * @param list<string> $slideIds
     *
     * @return array<string, mixed>|null
     */
    private function progress(int $assignmentId, array $slideIds): ?array
    {
        $rows = array_values(array_filter($this->snapshot->rows('presentation_progress'), static fn (array $p): bool => $p['assignment_id'] === $assignmentId));
        if ($rows === []) {
            return null;
        }
        // Several content versions may each have a row; the newest is where they got to.
        usort($rows, static fn (array $a, array $b): int => strcmp(self::str($b['updated_at']), self::str($a['updated_at'])));
        $row = $rows[0];
        $viewed = array_values(array_unique(array_filter(is_array($row['viewed_slide_ids']) ? $row['viewed_slide_ids'] : [], 'is_string')));
        $unknown = array_values(array_diff($viewed, $slideIds));
        $subject = 'presentation_progress#' . self::int($row['id']);
        if ($unknown !== []) {
            $this->findings->warning('UNKNOWN_SLIDES', $subject, sprintf('names %d slide(s) the platform deck does not have (%s); slide progress is not migrated.', count($unknown), implode(', ', array_slice($unknown, 0, 5))));

            return null;
        }
        $ordered = array_values(array_intersect($slideIds, $viewed));

        return [
            'sourceTable' => 'presentation_progress',
            'sourceId' => self::int($row['id']),
            'slideIds' => $ordered,
            'reachedFinal' => $slideIds !== [] && in_array($slideIds[count($slideIds) - 1], $ordered, true),
            'lastSeenAtUtc' => $row['updated_at'],
        ];
    }

    /**
     * @param array{questions: list<array{questionId: int, ordinal: int, prompt: string, optionKeys: list<string>, correctOptionKey: string}>, passMarkPercent: ?int} $target
     *
     * @return list<array<string, mixed>>
     */
    private function attempts(int $traineeId, int $moduleId, array $target): array
    {
        $rows = array_values(array_filter($this->snapshot->rows('attempts'), static fn (array $a): bool => $a['trainee_id'] === $traineeId && $a['module_id'] === $moduleId));
        usort($rows, static fn (array $a, array $b): int => [self::str($a['started_at']), self::int($a['id'])] <=> [self::str($b['started_at']), self::int($b['id'])]);

        $attempts = [];
        foreach ($rows as $row) {
            $id = self::int($row['id']);
            $subject = 'attempts#' . $id;
            if ($row['submitted_at'] === null) {
                $this->findings->info('OPEN_ATTEMPT_NOT_CARRIED', $subject, 'an attempt that was started and never submitted is not migrated.');
                continue;
            }
            $answers = $this->answers($row, $target, $subject);
            if ($answers === null) {
                continue;
            }
            $correct = count(array_filter($answers, static fn (array $a): bool => $a['isCorrect'] === true));
            $total = count($target['questions']);
            if (AssessmentGrader::isPass($correct, $total, $target['passMarkPercent']) !== ($row['passed'] === true)) {
                $this->findings->warning('PASS_DECISION_DIFFERS', $subject, sprintf('%d of %d correct: the source recorded %s, the platform would decide %s. Carried as the source recorded it.', $correct, $total, $row['passed'] === true ? 'a pass' : 'a fail', $row['passed'] === true ? 'a fail' : 'a pass'));
            }

            $attempts[] = [
                'sourceTable' => 'attempts',
                'sourceId' => $id,
                'number' => count($attempts) + 1,
                'startedAtUtc' => $row['started_at'],
                'submittedAtUtc' => $row['submitted_at'],
                'scorePercent' => self::int($row['score_percent']),
                'isPass' => $row['passed'] === true,
                'answers' => $answers,
                'supervisorFeedback' => self::nullable($row['supervisor_feedback']),
                'receivedByHr' => self::nullable($row['received_by_hr']),
                'reviewedAtUtc' => $row['reviewed_at'],
                'reviewerUserId' => $this->platformUserId($row['reviewed_by']),
                'documents' => array_values(array_filter([
                    $this->document($row, 'attempts', 'completed_pdf_base64', 'pdf_sha256', 'assessment'),
                    $this->document($row, 'attempts', 'reviewed_pdf_base64', 'reviewed_pdf_sha256', 'assessment'),
                ])),
            ];
        }

        return $attempts;
    }

    /**
     * Each answer placed on the platform's question at the same position. The
     * attempt is carried only if every question it was sat against is the
     * platform's — same count, same wording, same answer key.
     *
     * @param array<string, mixed> $row
     * @param array{questions: list<array{questionId: int, ordinal: int, prompt: string, optionKeys: list<string>, correctOptionKey: string}>} $target
     *
     * @return list<array{questionId: int, ordinal: int, selectedOptionKey: ?string, isCorrect: bool}>|null
     */
    private function answers(array $row, array $target, string $subject): ?array
    {
        $snapshot = is_array($row['question_snapshot']) ? array_values($row['question_snapshot']) : [];
        if (count($snapshot) !== count($target['questions'])) {
            $this->findings->warning('QUESTIONS_DIFFER', $subject, sprintf('was sat against %d questions; the platform version has %d. Not migrated.', count($snapshot), count($target['questions'])));

            return null;
        }
        $chosen = [];
        foreach (is_array($row['answers']) ? $row['answers'] : [] as $answer) {
            if (is_array($answer) && is_string($answer['questionPublicId'] ?? null)) {
                $chosen[$answer['questionPublicId']] = is_string($answer['chosenLabel'] ?? null) ? strtolower(trim($answer['chosenLabel'])) : null;
            }
        }

        $answers = [];
        foreach ($target['questions'] as $i => $question) {
            $sat = is_array($snapshot[$i]) ? $snapshot[$i] : [];
            $options = is_array($sat['options'] ?? null) ? $sat['options'] : [];
            $keys = [];
            $correctKey = null;
            foreach ($options as $option) {
                if (is_array($option) && is_string($option['label'] ?? null)) {
                    $key = strtolower(trim($option['label']));
                    $keys[] = $key;
                    if (($option['correct'] ?? false) === true) {
                        $correctKey = $key;
                    }
                }
            }
            if (self::normalise(self::str($sat['text'] ?? null)) !== self::normalise($question['prompt']) || $keys !== $question['optionKeys'] || $correctKey !== $question['correctOptionKey']) {
                $this->findings->warning('QUESTIONS_DIFFER', $subject, sprintf('question %d was not the platform\'s (wording, options or answer key differ). Not migrated.', $i + 1));

                return null;
            }
            $selected = $chosen[self::str($sat['public_id'] ?? null)] ?? null;
            $answers[] = [
                'questionId' => $question['questionId'],
                'ordinal' => $question['ordinal'],
                'selectedOptionKey' => $selected !== null && in_array($selected, $keys, true) ? $selected : null,
                'isCorrect' => $selected !== null && $selected === $question['correctOptionKey'],
            ];
        }

        return $answers;
    }

    /**
     * @param array<string, mixed> $item
     * @param array{questions: list<mixed>} $target
     */
    private function checkTrainingEvidence(array $item, array $target, string $subject): void
    {
        /** @var list<array<string, mixed>> $attempts */
        $attempts = $item['attempts'];
        $passed = array_filter($attempts, static fn (array $a): bool => $a['isPass'] === true);
        if ($item['status'] === 'completed' && $target['questions'] !== [] && $passed === []) {
            $this->findings->warning('COMPLETED_WITHOUT_PASS', $subject, 'is completed in the source but no passing attempt is carried with it.');
        }
        if ($item['status'] === 'completed' && ($item['progress'] === null || ($item['progress']['reachedFinal'] ?? false) !== true)) {
            $this->findings->info('COMPLETED_WITHOUT_FULL_PROGRESS', $subject, 'is completed in the source without carried progress to the last slide.');
        }
    }

    // ---------------------------------------------------------------- policy

    /** @return list<array<string, mixed>> */
    private function policyItems(int $traineeId): array
    {
        $items = [];
        foreach ($this->snapshot->rows('policy_assignments') as $row) {
            if ($row['trainee_id'] !== $traineeId) {
                continue;
            }
            $id = self::int($row['id']);
            $subject = 'policy_assignments#' . $id;
            $base = self::itemBase(self::POLICY, 'policy_assignments', $id, $row);
            if ($this->state->isMigrated('policy_assignments', $id)) {
                $items[] = self::skip($base + ['slug' => null], 'ALREADY_MIGRATED');
                continue;
            }
            $version = $this->snapshot->row('policy_versions', self::int($row['policy_version_id']));
            $policy = $version === null ? null : $this->snapshot->row('policies', self::int($version['policy_id']));
            if ($version === null || $policy === null) {
                $this->findings->blocking('DANGLING_REFERENCE', $subject, 'names a policy version that is not in the snapshot.');
                $items[] = self::skip($base + ['slug' => null], 'DANGLING_REFERENCE');
                continue;
            }
            $slug = self::str($policy['slug']);
            $target = $this->catalogue->policy($slug);
            $ack = $this->acknowledgement($traineeId, self::int($row['policy_version_id']));
            if ($target === null) {
                $this->findings->warning('POLICY_NOT_PUBLISHED', $subject, sprintf('Policy "%s" has no published platform version; not migrated.', $slug));
                $items[] = self::skip($base + ['slug' => $slug], 'POLICY_NOT_PUBLISHED');
                continue;
            }
            if ($version['status'] !== 'published') {
                $this->findings->warning('SUPERSEDED_VERSION', $subject, sprintf('is for version %d of "%s", which the source had %s; an acknowledgement of other wording is not an acknowledgement of the live policy. Not migrated.', self::int($version['version_number']), $slug, self::str($version['status'])));
                $items[] = self::skip($base + ['slug' => $slug], 'SUPERSEDED_VERSION');
                continue;
            }

            $acknowledgement = null;
            if ($ack !== null) {
                $acknowledgement = $this->declaration($ack, $target, 'policy_acknowledgements#' . self::int($ack['id']));
                if ($acknowledgement === false) {
                    $items[] = self::skip($base + ['slug' => $slug], 'DECLARATION_INCOMPLETE');
                    continue;
                }
            }
            // The source kept no status on a policy assignment; it is derived
            // from what happened, and these keys replace the defaults in $base.
            $items[] = [
                'slug' => $slug,
                'versionId' => $target['versionId'],
                'action' => MigrationPlan::ITEM_CREATE,
                'reason' => null,
                'status' => $acknowledgement !== null ? 'completed' : ($row['first_viewed_at'] !== null ? 'in_progress' : 'not_started'),
                'statusSetBy' => $acknowledgement !== null ? 'trainee' : 'system',
                'statusChangedAtUtc' => $acknowledgement['acknowledgedAtUtc'] ?? $row['first_viewed_at'] ?? $row['assigned_at'],
                'startedAtUtc' => $row['first_viewed_at'],
                'acknowledgement' => $acknowledgement,
            ] + $base;
        }

        return $items;
    }

    /** @return array<string, mixed>|null */
    private function acknowledgement(int $traineeId, int $versionId): ?array
    {
        foreach ($this->snapshot->rows('policy_acknowledgements') as $row) {
            if ($row['trainee_id'] === $traineeId && $row['policy_version_id'] === $versionId) {
                return $row;
            }
        }

        return null;
    }

    /**
     * The source's declaration in the platform's stored shape, checked the way
     * the platform checks one — or false, with the reason recorded.
     *
     * @param array<string, mixed> $ack
     * @param array{slug: string, documentReference: ?string} $target
     *
     * @return array<string, mixed>|false
     */
    private function declaration(array $ack, array $target, string $subject): array|false
    {
        $kind = PolicyDeclaration::kindFor($target['documentReference'], $target['slug']);
        $data = is_array($ack['declaration_data']) ? $ack['declaration_data'] : [];
        $values = null;
        try {
            if ($kind === PolicyDeclaration::SIGN_OFF) {
                $signature = $data['signatureDataUrl'] ?? null;
                SignatureImage::fromDataUrl('signature', $signature);
                if (trim(self::str($data['fullName'] ?? null)) === '' || ($data['accepted'] ?? null) !== true) {
                    throw InvalidOnboardingValue::required('declaration', 'name and agreement are required.');
                }
                $values = ['fullName' => trim(self::str($data['fullName'])), 'signature' => $signature, 'date' => self::str($data['date'] ?? null), 'accepted' => true];
                if (trim((string) $target['documentReference']) === PolicyDeclaration::VEHICLE_POLICY_REFERENCE) {
                    if (($data['licenceCopyAttached'] ?? null) !== true) {
                        throw InvalidOnboardingValue::required('driver licence copy', 'not confirmed.');
                    }
                    $values['licenceCopyAttached'] = true;
                }
            } elseif ($kind === PolicyDeclaration::CODE_OF_CONDUCT) {
                foreach (['role', 'conflictStatus', 'name', 'divisionRegion'] as $key) {
                    if (trim(self::str($data[$key] ?? null)) === '') {
                        throw InvalidOnboardingValue::required($key, 'missing.');
                    }
                }
                $values = [
                    'role' => self::str($data['role']),
                    'conflictStatus' => self::str($data['conflictStatus']),
                    'conflictDetails' => self::str($data['conflictDetails'] ?? null),
                    'willAvoidUndeclaredConflict' => ($data['willAvoidUndeclaredConflict'] ?? null) === true,
                    'understandsConsequences' => ($data['understandsConsequences'] ?? null) === true,
                    'name' => self::str($data['name']),
                    'divisionRegion' => self::str($data['divisionRegion']),
                    'date' => self::str($data['date'] ?? null),
                    'accepted' => ($data['accepted'] ?? $ack['accepted']) === true,
                ];
                if ($values['accepted'] === false) {
                    $this->findings->warning('CODE_OF_CONDUCT_NO', $subject, 'answered "No" to the Code of Conduct\'s final declaration; carried as given (it still completed the item in the source).');
                }
            }
        } catch (InvalidOnboardingValue $e) {
            $this->findings->warning('DECLARATION_INCOMPLETE', $subject, 'the source declaration does not meet the platform\'s (' . $e->getMessage() . '); the policy is not migrated.');

            return false;
        }

        return [
            'sourceTable' => 'policy_acknowledgements',
            'sourceId' => self::int($ack['id']),
            'acknowledgedAtUtc' => $ack['acknowledged_at'],
            'declarationKind' => $kind,
            'declaration' => $values,
        ];
    }

    // ------------------------------------------------------------------ form

    /** @return list<array<string, mixed>> */
    private function formItems(int $traineeId): array
    {
        $items = [];
        foreach ($this->snapshot->rows('form_assignments') as $row) {
            if ($row['trainee_id'] !== $traineeId) {
                continue;
            }
            $id = self::int($row['id']);
            $subject = 'form_assignments#' . $id;
            $base = self::itemBase(self::FORM, 'form_assignments', $id, $row);
            if ($this->state->isMigrated('form_assignments', $id)) {
                $items[] = self::skip($base + ['slug' => null], 'ALREADY_MIGRATED');
                continue;
            }
            $definition = $this->snapshot->row('form_definitions', self::int($row['form_definition_id']));
            if ($definition === null) {
                $this->findings->blocking('DANGLING_REFERENCE', $subject, 'names a form that is not in the snapshot.');
                $items[] = self::skip($base + ['slug' => null], 'DANGLING_REFERENCE');
                continue;
            }
            $slug = self::str($definition['slug']);
            $target = $this->catalogue->form($slug);
            if ($target === null) {
                $this->findings->warning('FORM_NOT_PUBLISHED', $subject, sprintf('Form "%s" has no published platform version; not migrated.', $slug));
                $items[] = self::skip($base + ['slug' => $slug], 'FORM_NOT_PUBLISHED');
                continue;
            }

            $draftData = is_array($row['draft_data']) ? $row['draft_data'] : [];
            $current = SourceFormTranslator::translate($slug, $draftData);
            $this->reportDropped($current->dropped, $subject . ' draft');

            $submissions = [];
            $blocked = false;
            foreach ($this->submissions($id) as $submission) {
                $sid = self::int($submission['id']);
                $translated = SourceFormTranslator::translate($slug, is_array($submission['submitted_data']) ? $submission['submitted_data'] : []);
                $this->reportDropped($translated->dropped, 'form_submissions#' . $sid);
                $unclassified = array_values(array_diff(array_keys($translated->trainee), array_keys($target['classifications'])));
                if ($unclassified !== []) {
                    $this->findings->warning('UNCLASSIFIED_FIELDS', 'form_submissions#' . $sid, 'answers fields the platform form has not classified (' . implode(', ', $unclassified) . '); the form is not migrated.');
                    $blocked = true;
                    break;
                }
                try {
                    FormSubmissionRules::check(['fields' => $target['fields']], $translated->trainee, FormSchemaRules::FILLED_BY_STARTER);
                } catch (InvalidOnboardingValue $e) {
                    // A revision HR sent back was expected to be incomplete.
                    $sentBack = $submission['review_outcome'] === 'fix_and_resubmit';
                    $message = 'would not pass the platform form\'s rules today (' . $e->getMessage() . '); carried as submitted' . ($sentBack ? ' (it was sent back).' : '.');
                    $sentBack
                        ? $this->findings->info('SUBMISSION_FAILS_CURRENT_RULES', 'form_submissions#' . $sid, $message)
                        : $this->findings->warning('SUBMISSION_FAILS_CURRENT_RULES', 'form_submissions#' . $sid, $message);
                }
                $submissions[] = [
                    'sourceTable' => 'form_submissions',
                    'sourceId' => $sid,
                    'revision' => self::int($submission['revision_number']),
                    'submittedAtUtc' => $submission['submitted_at'],
                    'answers' => $translated->trainee,
                    'reviewOutcome' => self::str($submission['review_outcome']),
                    'reviewNote' => self::nullable($submission['review_note']),
                    'reviewedAtUtc' => $submission['reviewed_at'],
                    'reviewerUserId' => $this->platformUserId($submission['reviewed_by']),
                    'documents' => array_values(array_filter([$this->document($submission, 'form_submissions', 'completed_pdf_base64', 'pdf_sha256', self::documentKind($slug))])),
                ];
            }
            if ($blocked) {
                $items[] = self::skip($base + ['slug' => $slug], 'UNCLASSIFIED_FIELDS');
                continue;
            }

            $status = self::str($row['status']);
            $open = !in_array($status, ['submitted', 'completed'], true);
            $items[] = $base + [
                'slug' => $slug,
                'versionId' => $target['versionId'],
                'action' => MigrationPlan::ITEM_CREATE,
                'reason' => null,
                'submissions' => $submissions,
                'draft' => $open && $current->trainee !== [] ? ['answers' => $current->trainee, 'savedAtUtc' => $row['draft_saved_at'] ?? $row['status_changed_at']] : null,
                'contributions' => array_filter(['hr' => $current->hr, 'manager' => $current->manager]),
                'photos' => array_map(static fn (array $p): array => ['sourceId' => $p['sourceId'], 'filename' => $p['filename'], 'sha256' => hash('sha256', (string) base64_decode($p['base64'], true))], $current->photos),
            ];
            if (!$open && $submissions === []) {
                $this->findings->warning('HANDED_IN_WITHOUT_SUBMISSION', $subject, 'is ' . $status . ' in the source with no submission to carry.');
            }
        }

        return $items;
    }

    /** @return list<array<string, mixed>> revisions in order */
    private function submissions(int $formAssignmentId): array
    {
        $rows = array_values(array_filter($this->snapshot->rows('form_submissions'), static fn (array $s): bool => $s['form_assignment_id'] === $formAssignmentId));
        usort($rows, static fn (array $a, array $b): int => self::int($a['revision_number']) <=> self::int($b['revision_number']));

        return $rows;
    }

    /** @param list<string> $dropped */
    private function reportDropped(array $dropped, string $subject): void
    {
        if ($dropped !== []) {
            $this->findings->warning('FIELDS_NOT_CARRIED', $subject, 'has answers with no platform field: ' . implode(', ', $dropped) . '.');
        }
    }

    private static function documentKind(string $formSlug): string
    {
        return match ($formSlug) {
            SourceFormTranslator::EMPLOYEE_DETAILS => 'employee_details',
            SourceFormTranslator::MEDICAL, SourceFormTranslator::DEED => 'legal_forms',
            default => 'workflow',
        };
    }

    // ---------------------------------------------------------------- shared

    /**
     * A stored PDF, checked against the sha256 the source recorded beside it.
     * The bytes stay in the snapshot; the plan names where they are.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>|null
     */
    private function document(array $row, string $table, string $column, string $shaColumn, string $kind): ?array
    {
        $base64 = $row[$column] ?? null;
        if (!is_string($base64) || $base64 === '') {
            return null;
        }
        $subject = $table . '#' . self::int($row['id']) . ' ' . $column;
        $bytes = base64_decode($base64, true);
        if ($bytes === false || $bytes === '') {
            $this->findings->warning('DOCUMENT_UNREADABLE', $subject, 'is not valid base64; the document is not migrated.');

            return null;
        }
        $sha = hash('sha256', $bytes);
        if ($sha !== strtolower(self::str($row[$shaColumn] ?? null))) {
            $this->findings->warning('DOCUMENT_HASH_MISMATCH', $subject, 'does not match the sha256 the source recorded; the document is not migrated.');

            return null;
        }
        if (!str_starts_with($bytes, '%PDF-')) {
            $this->findings->warning('DOCUMENT_NOT_PDF', $subject, 'is not a PDF; the document is not migrated.');

            return null;
        }

        return ['sourceTable' => $table, 'sourceId' => self::int($row['id']), 'column' => $column, 'kind' => $kind, 'sha256' => $sha, 'byteSize' => strlen($bytes)];
    }

    /** A source reviewer, as a platform user when their address has an account. */
    private function platformUserId(mixed $sourceTraineeId): ?int
    {
        if (!is_int($sourceTraineeId)) {
            return null;
        }
        $email = $this->emails[$sourceTraineeId] ?? null;

        return $email === null ? null : ($this->state->account($email)['userId'] ?? null);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function itemBase(string $kind, string $table, int $id, array $row): array
    {
        return [
            'kind' => $kind,
            'sourceTable' => $table,
            'sourceId' => $id,
            'status' => self::str($row['status'] ?? 'not_started'),
            'statusNote' => self::nullable($row['status_note'] ?? null),
            'statusSetBy' => self::str($row['status_set_by'] ?? 'system'),
            'statusChangedAtUtc' => $row['status_changed_at'] ?? $row['assigned_at'],
            'allocatedAtUtc' => $row['assigned_at'],
            'dueDate' => $row['due_on'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $item
     *
     * @return array<string, mixed>
     */
    private static function skip(array $item, string $reason): array
    {
        return ['action' => MigrationPlan::ITEM_SKIP, 'reason' => $reason] + $item;
    }

    /**
     * @param array<string, mixed> $person
     *
     * @return array<string, mixed>
     */
    private static function skipAll(array $person, string $reason): array
    {
        /** @var list<array<string, mixed>> $items */
        $items = $person['items'];
        $person['items'] = array_map(static fn (array $i): array => self::skip($i, $reason), $items);

        return $person;
    }

    /** @return array<string, int> */
    private static function sourceCounts(SourceSnapshot $snapshot): array
    {
        $counts = [];
        foreach (\TriCab\Core\Onboarding\Migration\Source\SourceSnapshotSchema::TABLES as $table => $_) {
            $counts[$table] = $snapshot->count($table);
        }

        return $counts;
    }

    private static function normalise(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private static function str(mixed $value): string
    {
        return is_string($value) ? $value : (is_int($value) ? (string) $value : '');
    }

    private static function int(mixed $value): int
    {
        return is_int($value) ? $value : 0;
    }

    private static function nullable(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
