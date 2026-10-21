<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\CableFit;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use TriCab\Core\CableFit\Application\CableFitCandidateService;
use TriCab\Core\CableFit\Application\CableFitHandoffService;
use TriCab\Core\CableFit\Domain\CandidateDeleteNotAllowed;
use TriCab\Core\CableFit\Domain\CandidateNotFound;
use TriCab\Core\CableFit\Domain\CandidateVersionConflict;
use TriCab\Core\CableFit\Domain\NprRequestNotAccessible;
use TriCab\Core\CableFit\Infrastructure\InMemoryCableFitCandidateRepository;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Npr\NprRequestSnapshot;

final class CableFitCandidateServiceTest extends TestCase
{
    private InMemoryCableFitCandidateRepository $repo;
    private FakeNprRequestReader $reader;
    private CableFitCandidateService $service;
    private int $seq = 0;

    protected function setUp(): void
    {
        $this->repo = new InMemoryCableFitCandidateRepository();
        $this->reader = new FakeNprRequestReader();
        $this->seq = 0;
        $this->service = new CableFitCandidateService(
            $this->repo,
            $this->reader,
            new CableFitHandoffService(),
            static fn (): DateTimeImmutable => new DateTimeImmutable('2026-10-19T00:00:00Z'),
            function (): string {
                return 'cand-' . ++$this->seq;
            },
        );
    }

    private function user(): AuthenticatedUser
    {
        return new AuthenticatedUser(7, 'user-7', 'tsd@example.test', 'TSD User', 'active', ['TSD'], ['TSD.CABLEFIT.USE']);
    }

    private function request(int $revision = 1): NprRequestSnapshot
    {
        return new NprRequestSnapshot(
            'npr-1',
            'NPR-0001',
            $revision,
            'Title',
            'Customer',
            [
                'voltage_rating' => 11,
                'main_core_count' => 3,
                'conductor_size' => 95,
            ],
            [],
        );
    }

    public function testCreateFromNprSeedsRevisionOneFromHandoff(): void
    {
        $this->reader->put($this->request());

        $id = $this->service->create($this->user(), 'npr-1', null, 'Cand A');
        $detail = $this->service->get($this->user(), $id);

        self::assertSame('cand-1', $id);
        self::assertSame(1, $detail->candidate->version);
        self::assertSame(1, $detail->revisionNumber);
        self::assertSame('npr-1', $detail->candidate->nprRequestPublicId);
        self::assertSame('default', $detail->candidate->nprVariantId);
        self::assertSame(1, $detail->sourceNprRevision);
        self::assertFalse($detail->stale);
        self::assertSame(11.0, $detail->construction['systemVoltage_kV']);
        self::assertArrayHasKey('conductor', $detail->provenance);
    }

    public function testCreateWithoutNprIsBlank(): void
    {
        $id = $this->service->create($this->user(), null, null, 'Research');
        $detail = $this->service->get($this->user(), $id);

        self::assertNull($detail->candidate->nprRequestPublicId);
        self::assertNull($detail->sourceNprRevision);
        self::assertSame([], $detail->construction);
        self::assertSame([], $detail->unresolvedRequirements);
        self::assertFalse($detail->stale);
    }

    public function testForbiddenWhenReaderReturnsNull(): void
    {
        $this->expectException(NprRequestNotAccessible::class);
        $this->service->create($this->user(), 'npr-hidden', null, 'X');
    }

    public function testListForbiddenWhenReaderReturnsNull(): void
    {
        $this->expectException(NprRequestNotAccessible::class);
        $this->service->listForNprRequest($this->user(), 'npr-hidden');
    }

    public function testGetIsNotFoundWhenRequestBecomesUnreadable(): void
    {
        $this->reader->put($this->request());
        $id = $this->service->create($this->user(), 'npr-1', null, 'A');

        $other = new CableFitCandidateService($this->repo, new FakeNprRequestReader());
        $this->expectException(CandidateNotFound::class);
        $other->get($this->user(), $id);
    }

    public function testUnknownCandidateIsNotFound(): void
    {
        $this->expectException(CandidateNotFound::class);
        $this->service->get($this->user(), 'missing');
    }

    public function testListReturnsCandidatesWithStaleFlag(): void
    {
        $this->reader->put($this->request());
        $this->service->create($this->user(), 'npr-1', null, 'A');
        $this->service->create($this->user(), 'npr-1', null, 'B');
        $this->service->create($this->user(), null, null, 'Unlinked');

        self::assertCount(2, $this->service->listForNprRequest($this->user(), 'npr-1'));
        self::assertFalse($this->service->listForNprRequest($this->user(), 'npr-1')[0]->stale);

        $this->reader->put($this->request(2));
        $rows = $this->service->listForNprRequest($this->user(), 'npr-1');
        self::assertTrue($rows[0]->stale);
        self::assertNotNull($rows[0]->staleReason);
    }

    public function testCandidateBecomesStaleWhenRequestIsRevised(): void
    {
        $this->reader->put($this->request());
        $id = $this->service->create($this->user(), 'npr-1', null, 'A');

        $this->reader->put($this->request(3));
        $detail = $this->service->get($this->user(), $id);

        self::assertTrue($detail->stale);
        self::assertSame(1, $detail->sourceNprRevision);
        self::assertSame(3, $detail->currentNprRevision);
        self::assertNotNull($detail->staleReason);
        self::assertStringContainsString('revision 3', (string) $detail->staleReason);
    }

    public function testSaveCreatesNewRevisionAndKeepsProvenance(): void
    {
        $this->reader->put($this->request());
        $id = $this->service->create($this->user(), 'npr-1', null, 'A');

        $v = $this->service->save($this->user(), $id, 1, ['systemVoltage_kV' => 22.0]);
        $detail = $this->service->get($this->user(), $id);

        self::assertSame(2, $v);
        self::assertSame(2, $detail->candidate->version);
        self::assertSame(22.0, $detail->construction['systemVoltage_kV']);
        self::assertArrayHasKey('conductor', $detail->provenance);
        self::assertSame(2, $this->repo->revisionCount($id));
    }

    public function testSaveWithWrongVersionConflicts(): void
    {
        $id = $this->service->create($this->user(), null, null, 'A');
        $this->service->save($this->user(), $id, 1, ['a' => 1]);

        try {
            $this->service->save($this->user(), $id, 1, ['a' => 2]);
            self::fail('Expected a version conflict');
        } catch (CandidateVersionConflict $e) {
            self::assertSame(1, $e->expectedVersion);
            self::assertSame(2, $e->currentVersion);
        }
        self::assertSame(2, $this->repo->revisionCount($id));
        self::assertSame(['a' => 1], $this->service->get($this->user(), $id)->construction);
    }

    public function testTheCreatorDeletesAndTheDesignLeavesEveryReadButStaysStored(): void
    {
        $this->reader->put($this->request());
        $id = $this->service->create($this->user(), 'npr-1', null, 'A');
        self::assertTrue($this->service->get($this->user(), $id)->canDelete);

        $this->service->delete($this->user(), $id);

        self::assertTrue($this->repo->isDeleted($id));
        self::assertSame(1, $this->repo->revisionCount($id), 'revisions are kept');
        self::assertSame([], $this->service->listForNprRequest($this->user(), 'npr-1'));
        $this->expectException(CandidateNotFound::class);
        $this->service->get($this->user(), $id);
    }

    public function testSomeoneElseCannotDeleteIt(): void
    {
        $id = $this->service->create($this->user(), null, null, 'A');
        $colleague = new AuthenticatedUser(8, 'user-8', 'other@example.test', 'Other', 'active', ['TSD'], ['TSD.CABLEFIT.USE']);
        self::assertFalse($this->service->get($colleague, $id)->canDelete);

        try {
            $this->service->delete($colleague, $id);
            self::fail('a colleague deleted it');
        } catch (CandidateDeleteNotAllowed) {
        }
        self::assertFalse($this->repo->isDeleted($id));
    }

    public function testAnAdministratorCanDeleteAnyonesDesign(): void
    {
        $id = $this->service->create($this->user(), null, null, 'A');
        $admin = new AuthenticatedUser(9, 'user-9', 'admin@example.test', 'Admin', 'active', ['EMPLOYEE', 'administrator'], ['TSD.CABLEFIT.USE']);
        self::assertTrue($this->service->get($admin, $id)->canDelete);

        $this->service->delete($admin, $id);

        self::assertTrue($this->repo->isDeleted($id));
    }

    public function testDeletingTwiceOrAMissingDesignIsNotFound(): void
    {
        $id = $this->service->create($this->user(), null, null, 'A');
        $this->service->delete($this->user(), $id);

        $this->expectException(CandidateNotFound::class);
        $this->service->delete($this->user(), $id);
    }
}
