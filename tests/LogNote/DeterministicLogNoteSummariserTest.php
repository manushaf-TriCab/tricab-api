<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\LogNote;

use PHPUnit\Framework\TestCase;
use TriCab\Api\LogNote\Infrastructure\DeterministicLogNoteSummariser;
use TriCab\Core\LogNote\Application\Service\SummariseLogNoteService;
use TriCab\Core\LogNote\Domain\Exception\InvalidLogNoteValue;

final class DeterministicLogNoteSummariserTest extends TestCase
{
    public function test_summarise_requires_body(): void
    {
        $service = new SummariseLogNoteService(new DeterministicLogNoteSummariser());

        $this->expectException(InvalidLogNoteValue::class);
        $service->execute('   ');
    }

    public function test_summarise_returns_prefixed_body(): void
    {
        $service = new SummariseLogNoteService(new DeterministicLogNoteSummariser());
        $result = $service->execute('Spoke with Sam. Need a quote on BV. Follow up Monday.');

        self::assertStringStartsWith('Summary:', $result->summarisedBody);
        self::assertSame('deterministic', $result->provider);
    }
}
