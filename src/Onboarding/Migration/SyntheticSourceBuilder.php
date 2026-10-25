<?php

declare(strict_types=1);

namespace TriCab\Api\Onboarding\Migration;

use RuntimeException;
use TriCab\Core\Onboarding\Migration\ContentMap;
use TriCab\Core\Onboarding\Migration\Source\SourceSnapshotSchema;
use TriCab\Core\Onboarding\Migration\Target\TargetCatalogue;

/**
 * Synthetic source data for the Phase 7 rehearsal, in the source's own table
 * shapes, pointing at content that is live on the platform (7.1).
 *
 * Deterministic: the same catalogue and base time give byte-identical rows,
 * so a rehearsal can be repeated and its reports compared. Every address is
 * `@example.test`, and the document is marked `"synthetic": true` — the only
 * kind of snapshot execution accepts while production is disabled.
 *
 * ## Who is in it
 *
 *  - `hr.admin@example.test` — a source admin (`is_admin`) with no work of
 *    their own, who reviewed others' work. Skipped as a person.
 *  - `hr.lead@example.test` — an admin only by `ADMIN_EMAILS`. Skipped.
 *  - the three demo people already on `tricab-mel` (Alex, Priya, Jordan — Jordan
 *    has left), matched by address;
 *  - `demo.casey.new@example.test` — no platform account: a GUEST account is
 *    created (owner decision, 25 Sep 2026).
 *
 * ## What they did
 *
 * Every status the source had, both assessment outcomes (a fail then a pass;
 * a pass awaiting HR's sign-off), partial and complete slide progress, both
 * declaration kinds and the vehicle policy's licence confirmation, a form sent
 * back once and then accepted, a draft, and a submission awaiting review.
 *
 * ## What should be refused or reported
 *
 *  - a module with no platform counterpart (`HR-DOC-999-V001`);
 *  - an acknowledgement of a policy version the source had since superseded;
 *  - an assignment the person already has on the platform (the demo seed gave
 *    Alex `demo-site-induction`) — the only overlap with the demo seed's
 *    allocations, so the rest of the history is exercised;
 *  - a profile photo, which the platform has nowhere to put.
 */
/**
 * @phpstan-type SnapshotDocument array{format: string, sourceSystem: string, synthetic: bool, extractedAtUtc: string, adminEmails: list<string>, tables: array<string, list<array<string, mixed>>>}
 */
final class SyntheticSourceBuilder
{
    public const UNMAPPED_REFERENCE = 'HR-DOC-999-V001';

    /** @var array<string, list<array<string, mixed>>> */
    private array $tables = [];
    /** @var array<string, int> */
    private array $nextId = [];
    /** @var array<int, string> module id => document reference */
    private array $references = [];
    private \DateTimeImmutable $base;

    public function __construct(private readonly TargetCatalogue $catalogue)
    {
    }

    /** @return SnapshotDocument */
    public function build(\DateTimeImmutable $base): array
    {
        $this->base = $base->setTimezone(new \DateTimeZone('UTC'));
        $this->tables = array_fill_keys(array_keys(SourceSnapshotSchema::TABLES), []);
        $this->nextId = [];

        $whse = $this->requireModule('corporate-whse-induction');
        $forklift = $this->requireModule('demo-forklift-awareness');
        $site = $this->requireModule('demo-site-induction');
        foreach (['employee-declaration', 'code-of-conduct', 'company-vehicle-policy', 'workplace-health-and-safety-policy', 'workplace-behaviour-policy', 'demo-whs-policy', 'handbook-welcome'] as $slug) {
            $this->catalogue->policy($slug) ?? throw new RuntimeException('The rehearsal needs the published policy ' . $slug . '.');
        }
        foreach (['demo-starter-details', 'demo-uniform-request'] as $slug) {
            $this->catalogue->form($slug) ?? throw new RuntimeException('The rehearsal needs the published form ' . $slug . '.');
        }

        // ---- people
        $admin = $this->trainee('HR Admin', 'hr.admin@example.test', isAdmin: true, day: 0);
        $this->trainee('HR Lead', 'hr.lead@example.test', day: 0);
        $alex = $this->trainee('Alex Ng', 'demo.alex.ng@example.test', position: 'Storeperson', day: 1, photo: true);
        $priya = $this->trainee('Priya Shah', 'demo.priya.shah@example.test', position: 'Driver', day: 2);
        $jordan = $this->trainee('Jordan Lee', 'demo.jordan.lee@example.test', position: 'Workshop hand', day: 3);
        $casey = $this->trainee('Casey New', 'demo.casey.new@example.test', position: 'Office', day: 4);

        // ---- content
        $mWhse = $this->module($whse, 'HR-DOC-009-V010', 'Corporate and WHSE Induction');
        $mForklift = $this->module($forklift, ContentMap::SYNTHETIC_PREFIX . 'demo-forklift-awareness', 'Forklift awareness');
        $mSite = $this->module($site, ContentMap::SYNTHETIC_PREFIX . 'demo-site-induction', 'Site induction');
        $mLegacy = $this->legacyModule();

        $fStarter = $this->formDefinition('demo-starter-details', 'Starter details');
        $fUniform = $this->formDefinition('demo-uniform-request', 'Uniform request');

        $pv = [];
        foreach (['employee-declaration', 'code-of-conduct', 'company-vehicle-policy', 'workplace-health-and-safety-policy', 'workplace-behaviour-policy', 'handbook-welcome'] as $slug) {
            $pv[$slug] = $this->policy($slug, [1 => 'published']);
        }
        // Two source versions: v1 archived, v2 published.
        [$whsOld, $whsLive] = $this->policyVersions('demo-whs-policy', [1 => 'archived', 2 => 'published']);

        // ---- Alex: the whole story
        $a = $this->assignment($alex, $mWhse, 'completed', 'reviewer', 10, note: 'Signed off with supervisor feedback.');
        $this->progress($alex, $a, $mWhse, $whse['slideIds'], 10, complete: true);
        $this->attempt($alex, $mWhse, $whse, 11, wrong: 7, reviewer: null);
        $this->attempt($alex, $mWhse, $whse, 12, wrong: 2, reviewer: $admin, feedback: 'Good understanding of site rules.');
        $a = $this->assignment($alex, $mSite, 'completed', 'system', 13);
        $this->progress($alex, $a, $mSite, $site['slideIds'], 13, complete: true);
        $this->attempt($alex, $mSite, $site, 13, wrong: 0, reviewer: null);
        $this->policyAck($alex, $pv['employee-declaration'], 14, ['fullName' => 'Alex Ng', 'signatureDataUrl' => self::signature('Alex Ng'), 'date' => $this->date(14), 'accepted' => true]);
        $this->policyAck($alex, $pv['code-of-conduct'], 14, [
            'role' => 'employee', 'conflictStatus' => 'declared', 'conflictDetails' => 'Sibling works for a supplier (synthetic).',
            'willAvoidUndeclaredConflict' => true, 'understandsConsequences' => true, 'name' => 'Alex Ng',
            'divisionRegion' => 'Melbourne', 'date' => $this->date(14), 'accepted' => true,
        ]);
        $this->policyAck($alex, $whsOld, 9, ['fullName' => 'Alex Ng', 'signatureDataUrl' => self::signature('Alex Ng'), 'date' => $this->date(9), 'accepted' => true]);
        $this->policyAssignment($alex, $pv['handbook-welcome'], 9, viewed: true);
        $fa = $this->formAssignment($alex, $fStarter, 'completed', 'reviewer', 16, ['preferredName' => 'Al', 'startDate' => '2026-08-03', 'parking' => 'yes', 'confirm' => true]);
        $this->submission($fa, 1, 15, ['preferredName' => 'Al', 'parking' => 'yes', 'confirm' => true], 'fix_and_resubmit', 'Start date missing.', $admin);
        $this->submission($fa, 2, 16, ['preferredName' => 'Al', 'startDate' => '2026-08-03', 'parking' => 'yes', 'confirm' => true], 'completed', '', $admin);

        // ---- Priya: part-way through
        $a = $this->assignment($priya, $mWhse, 'in_progress', 'trainee', 20);
        $this->progress($priya, $a, $mWhse, array_slice($whse['slideIds'], 0, 30), 20, complete: false);
        $a = $this->assignment($priya, $mSite, 'need_help', 'trainee', 21, note: 'Slide 2 will not load on my phone.');
        $this->progress($priya, $a, $mSite, array_slice($site['slideIds'], 0, 1), 21, complete: false);
        $this->policyAssignment($priya, $pv['workplace-behaviour-policy'], 20, viewed: false);
        $this->formAssignment($priya, $fUniform, 'in_progress', 'trainee', 21, ['shirtSize' => 's', 'bootSize' => '']);

        // ---- Jordan (has left): a pass waiting for HR
        $a = $this->assignment($jordan, $mWhse, 'submitted', 'trainee', 30);
        $this->progress($jordan, $a, $mWhse, $whse['slideIds'], 30, complete: true);
        $this->attempt($jordan, $mWhse, $whse, 31, wrong: 1, reviewer: null);
        $this->policyAck($jordan, $pv['employee-declaration'], 31, ['fullName' => 'Jordan Lee', 'signatureDataUrl' => self::signature('Jordan Lee'), 'date' => $this->date(31), 'accepted' => true]);
        $this->policyAck($jordan, $pv['company-vehicle-policy'], 31, ['fullName' => 'Jordan Lee', 'signatureDataUrl' => self::signature('Jordan Lee'), 'date' => $this->date(31), 'accepted' => true, 'licenceCopyAttached' => true]);
        $this->formAssignment($jordan, $fUniform, 'not_started', 'system', 30, []);

        // ---- Casey: new, no account
        $this->assignment($casey, $mForklift, 'not_started', 'system', 40);
        $this->assignment($casey, $mLegacy, 'in_progress', 'trainee', 40);
        $this->policyAck($casey, $pv['workplace-health-and-safety-policy'], 41, ['fullName' => 'Casey New', 'signatureDataUrl' => self::signature('Casey New'), 'date' => $this->date(41), 'accepted' => true]);
        $this->policyAssignment($casey, $whsLive, 41, viewed: true);
        $fa = $this->formAssignment($casey, $fStarter, 'submitted', 'trainee', 42, ['preferredName' => 'Case', 'startDate' => '2026-10-06', 'parking' => 'no', 'confirm' => true]);
        $this->submission($fa, 1, 42, ['preferredName' => 'Case', 'startDate' => '2026-10-06', 'parking' => 'no', 'confirm' => true], 'submitted', '', null);

        return [
            'format' => SourceSnapshotSchema::FORMAT,
            'sourceSystem' => SourceSnapshotSchema::SOURCE_SYSTEM,
            'synthetic' => true,
            'extractedAtUtc' => $this->base->format('Y-m-d\TH:i:s\Z'),
            'adminEmails' => ['hr.lead@example.test'],
            'tables' => $this->tables,
        ];
    }

    // ------------------------------------------------------------------ rows

    private function trainee(string $name, string $email, string $position = '', bool $isAdmin = false, int $day = 0, bool $photo = false): int
    {
        return $this->insert('trainees', [
            'public_id' => $this->uuid('trainee:' . $email),
            'full_name' => $name,
            'email' => $email,
            'mobile' => '0400 000 ' . str_pad((string) ($day + 100), 3, '0', STR_PAD_LEFT),
            'position' => $position,
            'department' => $position === '' ? '' : 'Operations',
            'branch' => 'Melbourne',
            'start_date' => $this->date($day + 7),
            'manager_name' => $position === '' ? '' : 'Jo Supervisor',
            'is_admin' => $isAdmin,
            'invited_at' => $this->at($day),
            'profile_photo_sha256' => $photo ? hash('sha256', 'synthetic-photo:' . $email) : null,
            'profile_photo_uploaded_at' => $photo ? $this->at($day + 1) : null,
            'created_at' => $this->at($day),
        ]);
    }

    /**
     * @param array{slideIds: list<string>, passMarkPercent: ?int, questions: list<array{prompt: string, optionKeys: list<string>, correctOptionKey: string}>} $target
     */
    private function module(array $target, string $reference, string $title): int
    {
        $id = $this->insert('modules', [
            'public_id' => $this->uuid('module:' . $reference),
            'title' => $title,
            'document_reference' => $reference,
            'material_type' => 'presentation',
            'presentation_content_version' => 'v1-synthetic',
            'presentation_slide_ids' => $target['slideIds'],
            'pass_mark' => $target['passMarkPercent'] ?? 80,
            'active' => true,
        ]);
        $this->references[$id] = $reference;
        foreach ($target['questions'] as $i => $question) {
            $this->insert('questions', [
                'public_id' => $this->uuid('question:' . $reference . ':' . $i),
                'module_id' => $id,
                'text' => $question['prompt'],
                'sort_order' => $i + 1,
                'options' => self::sourceOptions($question),
            ]);
        }

        return $id;
    }

    private function legacyModule(): int
    {
        $id = $this->insert('modules', [
            'public_id' => $this->uuid('module:' . self::UNMAPPED_REFERENCE),
            'title' => 'Manual handling quiz (retired)',
            'document_reference' => self::UNMAPPED_REFERENCE,
            'material_type' => 'legacy',
            'presentation_content_version' => null,
            'presentation_slide_ids' => [],
            'pass_mark' => 80,
            'active' => false,
        ]);
        $this->insert('questions', [
            'public_id' => $this->uuid('question:legacy:0'),
            'module_id' => $id,
            'text' => 'Bend your knees when lifting?',
            'sort_order' => 1,
            'options' => [['label' => 'A', 'text' => 'Yes', 'correct' => true], ['label' => 'B', 'text' => 'No', 'correct' => false]],
        ]);

        return $id;
    }

    private function formDefinition(string $slug, string $title): int
    {
        return $this->insert('form_definitions', [
            'public_id' => $this->uuid('form:' . $slug),
            'slug' => $slug,
            'version_number' => 1,
            'title' => $title,
            'access_group' => 'hr',
        ]);
    }

    /** @param array<int, string> $versions */
    private function policy(string $slug, array $versions): int
    {
        return $this->policyVersions($slug, $versions)[count($versions) - 1];
    }

    /**
     * @param array<int, string> $versions version number => status
     *
     * @return list<int> version ids, in order
     */
    private function policyVersions(string $slug, array $versions): array
    {
        $target = $this->catalogue->policy($slug) ?? throw new RuntimeException('No platform policy ' . $slug);
        $policyId = $this->insert('policies', [
            'public_id' => $this->uuid('policy:' . $slug),
            'slug' => $slug,
            'requires_acknowledgement' => $target['requiresAcknowledgement'],
        ]);
        $ids = [];
        foreach ($versions as $number => $status) {
            $ids[] = $this->insert('policy_versions', [
                'public_id' => $this->uuid('policy-version:' . $slug . ':' . $number),
                'policy_id' => $policyId,
                'version_number' => $number,
                'status' => $status,
                'title' => ucwords(str_replace('-', ' ', $slug)),
                'document_reference' => $target['documentReference'] ?? '',
                'content_hash' => hash('sha256', 'policy:' . $slug . ':' . $number),
            ]);
        }

        return $ids;
    }

    private function assignment(int $trainee, int $module, string $status, string $setBy, int $day, string $note = ''): int
    {
        return $this->insert('assignments', [
            'public_id' => $this->uuid('assignment:' . $trainee . ':' . $module),
            'trainee_id' => $trainee,
            'module_id' => $module,
            'assigned_at' => $this->at($day - 2),
            'due_on' => $this->date($day + 14),
            'status' => $status,
            'status_changed_at' => $this->at($day),
            'status_note' => $note,
            'status_set_by' => $setBy,
        ]);
    }

    /** @param list<string> $viewed */
    private function progress(int $trainee, int $assignment, int $module, array $viewed, int $day, bool $complete): void
    {
        $this->insert('presentation_progress', [
            'trainee_id' => $trainee,
            'assignment_id' => $assignment,
            'module_id' => $module,
            'content_version' => 'v1-synthetic',
            'last_slide_id' => $viewed === [] ? 'slide-001' : $viewed[count($viewed) - 1],
            'viewed_slide_ids' => $viewed,
            'completed_at' => $complete ? $this->at($day) : null,
            'updated_at' => $this->at($day),
        ]);
    }

    /**
     * @param array{passMarkPercent: ?int, questions: list<array{prompt: string, optionKeys: list<string>, correctOptionKey: string}>} $target
     */
    private function attempt(int $trainee, int $module, array $target, int $day, int $wrong, ?int $reviewer, string $feedback = ''): void
    {
        $reference = $this->references[$module] ?? throw new RuntimeException('Unknown synthetic module ' . $module);
        $questions = $target['questions'];
        $snapshot = [];
        $answers = [];
        $correct = 0;
        foreach ($questions as $i => $question) {
            $options = self::sourceOptions($question);
            $publicId = $this->uuid('question:' . $reference . ':' . $i);
            $right = strtoupper($question['correctOptionKey']);
            $isWrong = $i < $wrong;
            $chosen = $right;
            if ($isWrong) {
                foreach ($question['optionKeys'] as $key) {
                    if (strtoupper($key) !== $right) {
                        $chosen = strtoupper($key);
                        break;
                    }
                }
            }
            $correct += $isWrong ? 0 : 1;
            $snapshot[] = ['public_id' => $publicId, 'text' => $question['prompt'], 'reason' => null, 'options' => $options];
            $answers[] = ['questionPublicId' => $publicId, 'chosenLabel' => $chosen, 'wasCorrect' => !$isWrong];
        }
        $score = $questions === [] ? 0 : (int) round($correct / count($questions) * 100);
        $passed = $score >= ($target['passMarkPercent'] ?? 80);
        $pdf = self::pdf(sprintf('Synthetic assessment, trainee %d, module %d, day %d', $trainee, $module, $day));
        $reviewedPdf = $reviewer === null ? null : self::pdf(sprintf('Synthetic reviewed assessment, trainee %d, module %d', $trainee, $module));

        $this->insert('attempts', [
            'public_id' => $this->uuid('attempt:' . $trainee . ':' . $module . ':' . $day),
            'trainee_id' => $trainee,
            'module_id' => $module,
            'started_at' => $this->at($day, -1),
            'submitted_at' => $this->at($day),
            'score_percent' => $score,
            'passed' => $passed,
            'answers' => $answers,
            'question_snapshot' => $snapshot,
            'completed_pdf_base64' => base64_encode($pdf),
            'pdf_filename' => sprintf('assessment-%d-%d.pdf', $trainee, $day),
            'pdf_sha256' => hash('sha256', $pdf),
            'pdf_generated_at' => $this->at($day),
            'reviewed_pdf_base64' => $reviewedPdf === null ? null : base64_encode($reviewedPdf),
            'reviewed_pdf_filename' => $reviewedPdf === null ? null : sprintf('assessment-%d-%d-reviewed.pdf', $trainee, $day),
            'reviewed_pdf_sha256' => $reviewedPdf === null ? null : hash('sha256', $reviewedPdf),
            'reviewed_pdf_generated_at' => $reviewedPdf === null ? null : $this->at($day + 1),
            'supervisor_feedback' => $feedback,
            'received_by_hr' => $reviewer === null ? '' : 'HR Admin',
            'reviewed_at' => $reviewer === null ? null : $this->at($day + 1),
            'reviewed_by' => $reviewer,
        ]);
    }

    private function policyAssignment(int $trainee, int $version, int $day, bool $viewed): void
    {
        $this->insert('policy_assignments', [
            'public_id' => $this->uuid('policy-assignment:' . $trainee . ':' . $version),
            'trainee_id' => $trainee,
            'policy_version_id' => $version,
            'assigned_at' => $this->at($day - 1),
            'first_viewed_at' => $viewed ? $this->at($day) : null,
            'last_viewed_at' => $viewed ? $this->at($day) : null,
        ]);
    }

    /** @param array<string, mixed> $declaration */
    private function policyAck(int $trainee, int $version, int $day, array $declaration): void
    {
        $this->policyAssignment($trainee, $version, $day, viewed: true);
        $this->insert('policy_acknowledgements', [
            'public_id' => $this->uuid('policy-ack:' . $trainee . ':' . $version),
            'trainee_id' => $trainee,
            'policy_version_id' => $version,
            'declaration_data' => $declaration,
            'accepted' => ($declaration['accepted'] ?? true) === true,
            'acknowledged_at' => $this->at($day),
        ]);
    }

    /** @param array<string, mixed> $draft */
    private function formAssignment(int $trainee, int $form, string $status, string $setBy, int $day, array $draft): int
    {
        return $this->insert('form_assignments', [
            'public_id' => $this->uuid('form-assignment:' . $trainee . ':' . $form),
            'trainee_id' => $trainee,
            'form_definition_id' => $form,
            'assigned_at' => $this->at($day - 2),
            'due_on' => $this->date($day + 7),
            'status' => $status,
            'status_changed_at' => $this->at($day),
            'status_note' => '',
            'status_set_by' => $setBy,
            'draft_data' => $draft,
            'draft_saved_at' => $draft === [] ? null : $this->at($day),
            'workflow_stage' => in_array($status, ['submitted', 'completed'], true) ? 'hr' : 'employee',
        ]);
    }

    /** @param array<string, mixed> $data */
    private function submission(int $formAssignment, int $revision, int $day, array $data, string $outcome, string $note, ?int $reviewer): void
    {
        $pdf = self::pdf(sprintf('Synthetic form submission %d revision %d', $formAssignment, $revision));
        $this->insert('form_submissions', [
            'public_id' => $this->uuid('form-submission:' . $formAssignment . ':' . $revision),
            'form_assignment_id' => $formAssignment,
            'revision_number' => $revision,
            'submitted_data' => $data,
            'submitted_at' => $this->at($day),
            'completed_pdf_base64' => base64_encode($pdf),
            'pdf_filename' => sprintf('form-%d-r%d.pdf', $formAssignment, $revision),
            'pdf_sha256' => hash('sha256', $pdf),
            'pdf_generated_at' => $this->at($day),
            'review_outcome' => $outcome,
            'review_note' => $note,
            'reviewed_at' => $reviewer === null ? null : $this->at($day, 2),
            'reviewed_by' => $reviewer,
        ]);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * The source's option shape: a letter label, text, and the answer key as a flag.
     *
     * @param array{optionKeys: list<string>, correctOptionKey: string} $question
     *
     * @return list<array{label: string, text: string, correct: bool}>
     */
    private static function sourceOptions(array $question): array
    {
        return array_map(static fn (string $key): array => [
            'label' => strtoupper($key),
            'text' => 'Option ' . strtoupper($key),
            'correct' => $key === $question['correctOptionKey'],
        ], $question['optionKeys']);
    }

    /** @param array<string, mixed> $row */
    private function insert(string $table, array $row): int
    {
        $id = ($this->nextId[$table] ?? 0) + 1;
        $this->nextId[$table] = $id;
        $ordered = ['id' => $id];
        foreach (array_keys(SourceSnapshotSchema::TABLES[$table]) as $column) {
            if ($column !== 'id') {
                $ordered[$column] = $row[$column] ?? null;
            }
        }
        $this->tables[$table][] = $ordered;

        return $id;
    }

    /** @return array{slideIds: list<string>, passMarkPercent: ?int, questions: list<array{questionId: int, ordinal: int, prompt: string, optionKeys: list<string>, correctOptionKey: string}>, slug: string, versionId: int, versionPublicId: string, versionNumber: int} */
    private function requireModule(string $slug): array
    {
        return $this->catalogue->module($slug) ?? throw new RuntimeException('The rehearsal needs the published module ' . $slug . '.');
    }

    private function at(int $day, int $hours = 0): string
    {
        return $this->base->modify(sprintf('-%d days', 60 - $day))->modify(sprintf('%+d hours', $hours))->format('Y-m-d\TH:i:s.u\Z');
    }

    private function date(int $day): string
    {
        return $this->base->modify(sprintf('-%d days', 60 - $day))->format('Y-m-d');
    }

    private function uuid(string $seed): string
    {
        $h = md5('tricab-onboarding-synthetic:' . $seed);

        return sprintf('%s-%s-4%s-8%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 13, 3), substr($h, 17, 3), substr($h, 20, 12));
    }

    /** A small, valid, single-page PDF naming what it stands for. */
    public static function pdf(string $text): string
    {
        $stream = sprintf("BT /F1 12 Tf 72 720 Td (%s) Tj ET", str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text));
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            sprintf("<< /Length %d >>\nstream\n%s\nendstream", strlen($stream), $stream),
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= sprintf("%d 0 obj\n%s\nendobj\n", $i + 1, $object);
        }
        $xref = strlen($pdf);
        $pdf .= sprintf("xref\n0 %d\n0000000000 65535 f \n", count($objects) + 1);
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf . sprintf("trailer\n<< /Size %d /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF\n", count($objects) + 1, $xref);
    }

    /** A drawn-looking signature: a real PNG within the platform's signature limits. */
    public static function signature(string $name): string
    {
        $image = imagecreatetruecolor(240, 60);
        if ($image === false) {
            throw new RuntimeException('GD could not create an image.');
        }
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 255, 255, 255));
        $ink = (int) imagecolorallocate($image, 20, 20, 90);
        imagestring($image, 5, 12, 20, $name, $ink);
        imageline($image, 10, 45, 230, 42, $ink);
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();

        return 'data:image/png;base64,' . base64_encode($png);
    }
}
