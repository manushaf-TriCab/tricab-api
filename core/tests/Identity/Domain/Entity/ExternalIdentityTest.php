<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Identity\Domain\Entity;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Identity\Domain\Entity\ExternalIdentity;
use TriCab\Core\Identity\Domain\ValueObject\ExternalProvider;
use TriCab\Core\Identity\Domain\ValueObject\ExternalSubjectId;
use TriCab\Core\Identity\Domain\ValueObject\ExternalTenantId;

final class ExternalIdentityTest extends TestCase
{
    public function test_exposes_provider_subject_and_tenant(): void
    {
        $identity = $this->identity(
            subject: 'oid-123',
            tenant: '72f988bf-86f1-41af-91ab-2d7cd011db47',
        );

        self::assertTrue($identity->provider()->isMicrosoftEntra());
        self::assertSame('oid-123', $identity->subjectId()->toString());
        self::assertSame('72f988bf-86f1-41af-91ab-2d7cd011db47', $identity->tenantId()->toString());
    }

    public function test_equality_compares_all_components(): void
    {
        $a = $this->identity('oid-123', '72f988bf-86f1-41af-91ab-2d7cd011db47');
        $b = $this->identity('oid-123', '72F988BF-86F1-41AF-91AB-2D7CD011DB47');
        $c = $this->identity('oid-999', '72f988bf-86f1-41af-91ab-2d7cd011db47');

        self::assertTrue($a->equals($b));
        self::assertFalse($a->equals($c));
    }

    public function test_represents_linked_identity_without_token_fields(): void
    {
        $identity = $this->identity('subject', '00000000-0000-4000-8000-000000000001');

        self::assertSame('subject', $identity->subjectId()->toString());
        self::assertTrue($identity->provider()->equals(ExternalProvider::microsoftEntra()));
        self::assertSame('00000000-0000-4000-8000-000000000001', $identity->tenantId()->toString());
    }

    private function identity(string $subject, string $tenant): ExternalIdentity
    {
        return new ExternalIdentity(
            ExternalProvider::microsoftEntra(),
            ExternalSubjectId::fromString($subject),
            ExternalTenantId::fromString($tenant),
        );
    }
}
