<?php

declare(strict_types=1);

namespace TriCab\Api\Onboarding\Migration;

use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Core\Onboarding\Domain\ValueObject\PresentationManifest;
use TriCab\Core\Onboarding\Migration\Target\TargetCatalogue;

/**
 * Reads the platform's live content for the migration (Phase 7.3): per active
 * module, policy and form, its newest published version that has not been
 * retired or superseded. Read-only.
 */
final readonly class AzureSqlTargetCatalogueReader
{
    public function __construct(private SqlConnection $connection)
    {
    }

    public function read(): TargetCatalogue
    {
        return new TargetCatalogue($this->modules(), $this->policies(), $this->forms());
    }

    /** @return array<string, array{slug: string, versionId: int, versionPublicId: string, versionNumber: int, slideIds: list<string>, passMarkPercent: ?int, questions: list<array{questionId: int, ordinal: int, prompt: string, optionKeys: list<string>, correctOptionKey: string}>}> */
    private function modules(): array
    {
        $rows = $this->connection->fetchAll(<<<'SQL'
            SELECT m.[Slug], v.[TrainingModuleVersionId] AS [VersionId], LOWER(CONVERT(NVARCHAR(36), v.[PublicId])) AS [VersionPublicId],
                   v.[VersionNumber], v.[PresentationManifestJson], v.[PassMarkPercent]
            FROM [onboarding].[TrainingModules] m
            INNER JOIN [onboarding].[TrainingModuleVersions] v ON v.[TrainingModuleId] = m.[TrainingModuleId]
            WHERE m.[IsActive] = 1 AND v.[IsPublished] = 1 AND v.[RetiredAtUtc] IS NULL
            ORDER BY m.[Slug], v.[VersionNumber] DESC
            SQL);

        $modules = [];
        foreach ($rows as $row) {
            $slug = AzureSqlValueMapper::stringValue($row['Slug'] ?? '');
            if (isset($modules[$slug])) {
                continue;
            }
            $versionId = AzureSqlValueMapper::intValue($row['VersionId'] ?? 0);
            $modules[$slug] = [
                'slug' => $slug,
                'versionId' => $versionId,
                'versionPublicId' => AzureSqlValueMapper::stringValue($row['VersionPublicId'] ?? ''),
                'versionNumber' => AzureSqlValueMapper::intValue($row['VersionNumber'] ?? 0),
                'slideIds' => PresentationManifest::fromJson(AzureSqlValueMapper::stringValue($row['PresentationManifestJson'] ?? '[]'))->slideIds(),
                'passMarkPercent' => ($row['PassMarkPercent'] ?? null) === null ? null : AzureSqlValueMapper::intValue($row['PassMarkPercent']),
                'questions' => $this->questions($versionId),
            ];
        }

        return $modules;
    }

    /** @return list<array{questionId: int, ordinal: int, prompt: string, optionKeys: list<string>, correctOptionKey: string}> */
    private function questions(int $versionId): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT [QuestionId], [Ordinal], [Prompt], [OptionsJson], [CorrectOptionKey] FROM [onboarding].[Questions] WHERE [TrainingModuleVersionId] = :versionId ORDER BY [Ordinal]',
            [':versionId' => $versionId],
        );

        return array_map(static function (array $row): array {
            $options = json_decode(AzureSqlValueMapper::stringValue($row['OptionsJson'] ?? '[]'), true);

            return [
                'questionId' => AzureSqlValueMapper::intValue($row['QuestionId'] ?? 0),
                'ordinal' => AzureSqlValueMapper::intValue($row['Ordinal'] ?? 0),
                'prompt' => AzureSqlValueMapper::stringValue($row['Prompt'] ?? ''),
                'optionKeys' => array_values(array_map(
                    static fn (mixed $o): string => is_array($o) && is_string($o['key'] ?? null) ? $o['key'] : '',
                    is_array($options) ? $options : [],
                )),
                'correctOptionKey' => AzureSqlValueMapper::stringValue($row['CorrectOptionKey'] ?? ''),
            ];
        }, $rows);
    }

    /** @return array<string, array{slug: string, versionId: int, versionPublicId: string, versionNumber: int, documentReference: ?string, requiresAcknowledgement: bool}> */
    private function policies(): array
    {
        $rows = $this->connection->fetchAll(<<<'SQL'
            SELECT p.[Slug], v.[PolicyVersionId] AS [VersionId], LOWER(CONVERT(NVARCHAR(36), v.[PublicId])) AS [VersionPublicId],
                   v.[VersionNumber], v.[DocumentReference], v.[RequiresAcknowledgement]
            FROM [onboarding].[Policies] p
            INNER JOIN [onboarding].[PolicyVersions] v ON v.[PolicyId] = p.[PolicyId]
            WHERE p.[IsActive] = 1 AND v.[IsPublished] = 1 AND v.[SupersededAtUtc] IS NULL
            ORDER BY p.[Slug], v.[VersionNumber] DESC
            SQL);

        $policies = [];
        foreach ($rows as $row) {
            $slug = AzureSqlValueMapper::stringValue($row['Slug'] ?? '');
            if (isset($policies[$slug])) {
                continue;
            }
            $reference = $row['DocumentReference'] ?? null;
            $policies[$slug] = [
                'slug' => $slug,
                'versionId' => AzureSqlValueMapper::intValue($row['VersionId'] ?? 0),
                'versionPublicId' => AzureSqlValueMapper::stringValue($row['VersionPublicId'] ?? ''),
                'versionNumber' => AzureSqlValueMapper::intValue($row['VersionNumber'] ?? 0),
                'documentReference' => $reference === null ? null : AzureSqlValueMapper::stringValue($reference),
                'requiresAcknowledgement' => (bool) ($row['RequiresAcknowledgement'] ?? true),
            ];
        }

        return $policies;
    }

    /** @return array<string, array{slug: string, versionId: int, versionPublicId: string, versionNumber: int, accessGroup: string, fields: list<array<string, mixed>>, classifications: array<string, int>}> */
    private function forms(): array
    {
        $rows = $this->connection->fetchAll(<<<'SQL'
            SELECT d.[Slug], d.[AccessGroup], v.[FormDefinitionVersionId] AS [VersionId], LOWER(CONVERT(NVARCHAR(36), v.[PublicId])) AS [VersionPublicId],
                   v.[VersionNumber], v.[SchemaJson]
            FROM [onboarding].[FormDefinitions] d
            INNER JOIN [onboarding].[FormDefinitionVersions] v ON v.[FormDefinitionId] = d.[FormDefinitionId]
            WHERE d.[IsActive] = 1 AND v.[IsPublished] = 1 AND v.[RetiredAtUtc] IS NULL
            ORDER BY d.[Slug], v.[VersionNumber] DESC
            SQL);

        $forms = [];
        foreach ($rows as $row) {
            $slug = AzureSqlValueMapper::stringValue($row['Slug'] ?? '');
            if (isset($forms[$slug])) {
                continue;
            }
            $versionId = AzureSqlValueMapper::intValue($row['VersionId'] ?? 0);
            $schema = json_decode(AzureSqlValueMapper::stringValue($row['SchemaJson'] ?? '{}'), true);
            $fields = [];
            foreach (is_array($schema) && is_array($schema['fields'] ?? null) ? $schema['fields'] : [] as $field) {
                if (!is_array($field)) {
                    continue;
                }
                $named = [];
                foreach ($field as $key => $value) {
                    if (is_string($key)) {
                        $named[$key] = $value;
                    }
                }
                $fields[] = $named;
            }
            $classifications = [];
            foreach ($this->connection->fetchAll(
                'SELECT [FieldPath], [RetentionClassId] FROM [onboarding_hr].[FieldClassifications] WHERE [FormDefinitionVersionId] = :versionId',
                [':versionId' => $versionId],
            ) as $c) {
                $classifications[AzureSqlValueMapper::stringValue($c['FieldPath'] ?? '')] = AzureSqlValueMapper::intValue($c['RetentionClassId'] ?? 0);
            }
            $forms[$slug] = [
                'slug' => $slug,
                'versionId' => $versionId,
                'versionPublicId' => AzureSqlValueMapper::stringValue($row['VersionPublicId'] ?? ''),
                'versionNumber' => AzureSqlValueMapper::intValue($row['VersionNumber'] ?? 0),
                'accessGroup' => AzureSqlValueMapper::stringValue($row['AccessGroup'] ?? 'hr'),
                'fields' => $fields,
                'classifications' => $classifications,
            ];
        }

        return $forms;
    }
}
