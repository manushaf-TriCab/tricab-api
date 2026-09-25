<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Onboarding;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Onboarding\Document\AssessmentPdfMapper;
use TriCab\Api\Onboarding\TrainingImport\SourceTrainingConverter;
use TriCab\Core\Onboarding\Application\DTO\Document\AssessmentDocumentInput;
use TriCab\Core\Onboarding\Application\DTO\Document\AssessmentSignOff;
use TriCab\Api\Onboarding\TrainingImport\TriCabTrainingImporter;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Identity\Domain\Service\UuidV4Generator;
use TriCab\Core\Onboarding\Application\DTO\Authoring\AuthoredContent;
use TriCab\Core\Onboarding\Application\Service\AuthorOnboardingContentService;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingAdminAuditLog;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingContentRepository;

/**
 * The three TriCab training modules, from the source to platform drafts.
 *
 * Runs against the real source checkout beside the platform, and is skipped
 * where it is not there — the conversion is only meaningful against the real
 * answer key.
 *
 * @phpstan-import-type TrainingRecord from SourceTrainingConverter
 * @phpstan-import-type TrainingQuestion from SourceTrainingConverter
 */
final class TrainingImportTest extends TestCase
{
    private const SOURCE = __DIR__ . '/../../../../hr/onboarding-workflow/tricab-training-vercel';

    /** @var list<TrainingRecord> */
    private array $records = [];

    protected function setUp(): void
    {
        if (!is_file(self::SOURCE . '/db/training-content.sql')) {
            self::markTestSkipped('tricab-training-vercel is not checked out beside the platform.');
        }
        $this->records = (new SourceTrainingConverter())->convert(self::SOURCE);
    }

    public function test_the_three_modules_convert_with_the_sources_own_counts(): void
    {
        $summary = array_map(static fn (array $r): array => [
            $r['slug'],
            $r['documentReference'],
            count($r['content']['slides']),
            count($r['content']['questions']),
            $r['content']['passMarkPercent'],
        ], $this->records);

        self::assertSame([
            ['corporate-whse-induction', 'HR-DOC-009-V010', 88, 17, 80],
            ['respect-at-work', 'HR-DOC-098-V000', 79, 22, 80],
            ['workplace-sexual-harassment', 'HR-DOC-094-V000', 71, 22, 80],
        ], $summary);
    }

    public function test_the_answer_key_and_sheet_order_are_the_sources(): void
    {
        $whse = $this->records[0]['content']['questions'];

        self::assertSame('Which PPE must be always worn whilst on the factory floor?', $whse[0]['prompt']);
        self::assertSame('a', $whse[0]['correctOptionKey']);
        self::assertStringStartsWith('I was walking through the factory', $whse[5]['prompt'], 'SQL quotes unescaped, order kept');
        self::assertSame('b', $whse[5]['correctOptionKey']);
        foreach ($this->records as $record) {
            foreach ($record['content']['questions'] as $i => $question) {
                self::assertNotNull($question['correctReason'], $record['slug'] . ' Q' . ($i + 1) . ' has its explanation');
            }
        }
    }

    public function test_every_module_passes_the_platforms_authoring_rules(): void
    {
        foreach ($this->records as $record) {
            $content = AuthoredContent::fromInput(AuthoredContent::TYPE_TRAINING, $record['content'], null);
            self::assertCount(count($record['content']['questions']), $content->questions);
            self::assertSame('slide-001', $content->slides[0]['id'], 'slide ids kept, so progress can be migrated');
        }
    }

    public function test_the_import_makes_three_drafts_once(): void
    {
        $content = new InMemoryOnboardingContentRepository();
        $importer = new TriCabTrainingImporter($content, new AuthorOnboardingContentService($content, new InMemoryOnboardingAdminAuditLog(), new UuidV4Generator()));
        $actor = new ActorContext(40, 'hr-public-id', 'hr@example.test');

        $results = $importer->apply($importer->plan($this->records), $actor);

        self::assertSame(array_fill(0, 3, 'created; draft saved, not published'), array_column($results, 'result'));
        foreach ($content->listItems() as $summary) {
            if ($summary->itemType !== AuthoredContent::TYPE_TRAINING || !in_array($summary->slug, ['corporate-whse-induction', 'respect-at-work', 'workplace-sexual-harassment'], true)) {
                continue;
            }
            $item = $content->findItem(AuthoredContent::TYPE_TRAINING, $summary->publicId);
            self::assertNotNull($item);
            $versions = $content->listVersions($item);
            self::assertCount(1, $versions);
            $version = $content->findVersion($item, $versions[0]->publicId);
            self::assertNotNull($version);
            self::assertTrue($version->isDraft());
        }

        $again = $importer->plan($this->records);
        self::assertSame(array_fill(0, 3, TriCabTrainingImporter::EXISTS), array_column($again, 'action'));
    }

    public function test_every_imported_module_marks_onto_its_paper_sheet(): void
    {
        $mapper = new AssessmentPdfMapper(dirname(__DIR__, 2) . '/resources/onboarding/form-templates');
        $pages = ['corporate-whse-induction' => 4, 'respect-at-work' => 5, 'workplace-sexual-harassment' => 5];

        foreach ($this->records as $record) {
            $document = $mapper->render($this->input($record['slug'], $record['content']['questions']));
            self::assertNotNull($document, $record['slug']);
            self::assertSame('assessment', $document->documentKind);
            $file = tempnam(sys_get_temp_dir(), 'pdf');
            self::assertIsString($file);
            file_put_contents($file, $document->bytes);
            self::assertSame($pages[$record['slug']], (new \setasign\Fpdi\Fpdi())->setSourceFile($file), $record['slug']);
            unlink($file);
        }
    }

    public function test_the_whse_sheet_takes_hr_s_sign_off_on_its_result_page(): void
    {
        $mapper = new AssessmentPdfMapper(dirname(__DIR__, 2) . '/resources/onboarding/form-templates');
        $whse = array_values(array_filter($this->records, static fn (array $r): bool => $r['slug'] === 'corporate-whse-induction'))[0];

        $plain = $mapper->render($this->input('corporate-whse-induction', $whse['content']['questions']));
        $signed = $mapper->render($this->input(
            'corporate-whse-induction',
            $whse['content']['questions'],
            new AssessmentSignOff(str_repeat('Works safely around the racking and the forklift bay. ', 6), 'Hana Reviewer', '2026-09-25'),
        ));

        self::assertNotNull($plain);
        self::assertNotNull($signed);
        self::assertNotSame($plain->bytes, $signed->bytes);
        $file = tempnam(sys_get_temp_dir(), 'pdf');
        self::assertIsString($file);
        file_put_contents($file, $signed->bytes);
        self::assertSame(4, (new \setasign\Fpdi\Fpdi())->setSourceFile($file));
        unlink($file);
    }

    public function test_a_question_count_no_sheet_has_is_refused_and_an_unknown_module_has_no_sheet(): void
    {
        $mapper = new AssessmentPdfMapper(dirname(__DIR__, 2) . '/resources/onboarding/form-templates');

        self::assertNull($mapper->render($this->input('demo-site-induction', [])));
        $this->expectException(\RuntimeException::class);
        $mapper->render($this->input('respect-at-work', array_slice($this->records[1]['content']['questions'], 0, 5)));
    }

    /**
     * @param list<TrainingQuestion> $questions
     */
    private function input(string $slug, array $questions, ?AssessmentSignOff $signOff = null): AssessmentDocumentInput
    {
        return new AssessmentDocumentInput(
            $slug,
            'Module',
            array_map(static fn (array $q): array => ['optionKeys' => array_column($q['options'], 'key'), 'chosenKey' => $q['correctOptionKey']], $questions),
            100,
            true,
            80,
            '2026-09-24T01:00:00Z',
            'Sam Synthetic',
            'Storeperson',
            'Warehouse',
            'Dandenong',
            'Morgan Manager',
            $signOff,
        );
    }
}
