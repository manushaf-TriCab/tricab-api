<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Migration\Source;

/**
 * Exactly what the migration reads from the source, `tricab-training-vercel`
 * (Neon Postgres, `db/schema.sql`): a fixed allow-list of tables and, per
 * table, of columns with the type each is read as (Phase 7.1).
 *
 * The extractor builds its SELECTs from this list and nothing else, and a
 * snapshot carrying any table or column not on it is refused on load — so a
 * snapshot cannot be made to carry sign-in codes, sessions or anything else
 * the migration has no business holding.
 *
 * ## Never read
 *
 *  - `otp_challenges` — code hashes and request IPs; sign-in is the platform's.
 *  - `notification_log` — operational; it only stopped the reminder job sending
 *    twice in one day.
 *  - `cohorts` — TriCab does not use them (owner, 21 Sep 2026).
 *  - Sessions have no table: they are stateless JWTs and nothing is migrated.
 *
 * ## Deliberately not read from tables that are
 *
 *  - `trainees.profile_photo_base64` — the platform has no profile photo. Its
 *    hash and upload time are read so the preview can count what is left behind.
 *  - `modules` authoring columns, `policy_versions` wording and source-file
 *    columns — content is already on the platform (5.4 imports); only what is
 *    needed to match a record to it is read.
 *  - `assignments.available_from` — the platform has a due date only.
 */
final class SourceSnapshotSchema
{
    public const FORMAT = 'tricab.onboarding.source-snapshot/1';
    public const SOURCE_SYSTEM = 'tricab-training-vercel';

    public const ID = 'id';
    public const UUID = 'uuid';
    public const TEXT = 'text';
    public const INT = 'int';
    public const BOOL = 'bool';
    public const DATE = 'date';
    public const TIMESTAMP = 'timestamp';
    public const JSON = 'json';

    /** @var list<string> */
    public const NEVER_READ = ['otp_challenges', 'notification_log', 'cohorts'];

    /**
     * Table => column => type, in dependency order (a table only refers to the
     * tables above it).
     *
     * @var array<string, array<string, string>>
     */
    public const TABLES = [
        'trainees' => [
            'id' => self::ID,
            'public_id' => self::UUID,
            'full_name' => self::TEXT,
            'email' => self::TEXT,
            'mobile' => self::TEXT,
            'position' => self::TEXT,
            'department' => self::TEXT,
            'branch' => self::TEXT,
            'start_date' => self::DATE,
            'manager_name' => self::TEXT,
            'is_admin' => self::BOOL,
            'invited_at' => self::TIMESTAMP,
            'profile_photo_sha256' => self::TEXT,
            'profile_photo_uploaded_at' => self::TIMESTAMP,
            'created_at' => self::TIMESTAMP,
        ],
        'modules' => [
            'id' => self::ID,
            'public_id' => self::UUID,
            'title' => self::TEXT,
            'document_reference' => self::TEXT,
            'material_type' => self::TEXT,
            'presentation_content_version' => self::TEXT,
            'presentation_slide_ids' => self::JSON,
            'pass_mark' => self::INT,
            'active' => self::BOOL,
        ],
        'questions' => [
            'id' => self::ID,
            'public_id' => self::UUID,
            'module_id' => self::ID,
            'text' => self::TEXT,
            'sort_order' => self::INT,
            'options' => self::JSON,
        ],
        'form_definitions' => [
            'id' => self::ID,
            'public_id' => self::UUID,
            'slug' => self::TEXT,
            'version_number' => self::INT,
            'title' => self::TEXT,
            'access_group' => self::TEXT,
        ],
        'policies' => [
            'id' => self::ID,
            'public_id' => self::UUID,
            'slug' => self::TEXT,
            'requires_acknowledgement' => self::BOOL,
        ],
        'policy_versions' => [
            'id' => self::ID,
            'public_id' => self::UUID,
            'policy_id' => self::ID,
            'version_number' => self::INT,
            'status' => self::TEXT,
            'title' => self::TEXT,
            'document_reference' => self::TEXT,
            'content_hash' => self::TEXT,
        ],
        'assignments' => [
            'id' => self::ID,
            'public_id' => self::UUID,
            'trainee_id' => self::ID,
            'module_id' => self::ID,
            'assigned_at' => self::TIMESTAMP,
            'due_on' => self::DATE,
            'status' => self::TEXT,
            'status_changed_at' => self::TIMESTAMP,
            'status_note' => self::TEXT,
            'status_set_by' => self::TEXT,
        ],
        'presentation_progress' => [
            'id' => self::ID,
            'trainee_id' => self::ID,
            'assignment_id' => self::ID,
            'module_id' => self::ID,
            'content_version' => self::TEXT,
            'last_slide_id' => self::TEXT,
            'viewed_slide_ids' => self::JSON,
            'completed_at' => self::TIMESTAMP,
            'updated_at' => self::TIMESTAMP,
        ],
        'attempts' => [
            'id' => self::ID,
            'public_id' => self::UUID,
            'trainee_id' => self::ID,
            'module_id' => self::ID,
            'started_at' => self::TIMESTAMP,
            'submitted_at' => self::TIMESTAMP,
            'score_percent' => self::INT,
            'passed' => self::BOOL,
            'answers' => self::JSON,
            'question_snapshot' => self::JSON,
            'completed_pdf_base64' => self::TEXT,
            'pdf_filename' => self::TEXT,
            'pdf_sha256' => self::TEXT,
            'pdf_generated_at' => self::TIMESTAMP,
            'reviewed_pdf_base64' => self::TEXT,
            'reviewed_pdf_filename' => self::TEXT,
            'reviewed_pdf_sha256' => self::TEXT,
            'reviewed_pdf_generated_at' => self::TIMESTAMP,
            'supervisor_feedback' => self::TEXT,
            'received_by_hr' => self::TEXT,
            'reviewed_at' => self::TIMESTAMP,
            'reviewed_by' => self::ID,
        ],
        'form_assignments' => [
            'id' => self::ID,
            'public_id' => self::UUID,
            'trainee_id' => self::ID,
            'form_definition_id' => self::ID,
            'assigned_at' => self::TIMESTAMP,
            'due_on' => self::DATE,
            'status' => self::TEXT,
            'status_changed_at' => self::TIMESTAMP,
            'status_note' => self::TEXT,
            'status_set_by' => self::TEXT,
            'draft_data' => self::JSON,
            'draft_saved_at' => self::TIMESTAMP,
            'workflow_stage' => self::TEXT,
        ],
        'form_submissions' => [
            'id' => self::ID,
            'public_id' => self::UUID,
            'form_assignment_id' => self::ID,
            'revision_number' => self::INT,
            'submitted_data' => self::JSON,
            'submitted_at' => self::TIMESTAMP,
            'completed_pdf_base64' => self::TEXT,
            'pdf_filename' => self::TEXT,
            'pdf_sha256' => self::TEXT,
            'pdf_generated_at' => self::TIMESTAMP,
            'review_outcome' => self::TEXT,
            'review_note' => self::TEXT,
            'reviewed_at' => self::TIMESTAMP,
            'reviewed_by' => self::ID,
        ],
        'policy_assignments' => [
            'id' => self::ID,
            'public_id' => self::UUID,
            'trainee_id' => self::ID,
            'policy_version_id' => self::ID,
            'assigned_at' => self::TIMESTAMP,
            'first_viewed_at' => self::TIMESTAMP,
            'last_viewed_at' => self::TIMESTAMP,
        ],
        'policy_acknowledgements' => [
            'id' => self::ID,
            'public_id' => self::UUID,
            'trainee_id' => self::ID,
            'policy_version_id' => self::ID,
            'declaration_data' => self::JSON,
            'accepted' => self::BOOL,
            'acknowledged_at' => self::TIMESTAMP,
        ],
    ];
}
