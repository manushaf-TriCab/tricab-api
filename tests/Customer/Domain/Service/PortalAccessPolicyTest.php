<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Customer\Domain\Service;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Customer\Domain\Exception\InvalidPortalAccessTransition;
use TriCab\Core\Customer\Domain\Service\PortalAccessPolicy;
use TriCab\Core\Customer\Domain\ValueObject\PortalAccessStatus;

final class PortalAccessPolicyTest extends TestCase
{
    private PortalAccessPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new PortalAccessPolicy();
    }

    /**
     * @return list<array{string, string}>
     */
    public static function allowedTransitionsProvider(): array
    {
        return [
            [PortalAccessStatus::NOT_INVITED, PortalAccessStatus::INVITED],
            [PortalAccessStatus::NOT_INVITED, PortalAccessStatus::DECLINED],
            [PortalAccessStatus::INVITED, PortalAccessStatus::ACTIVE],
            [PortalAccessStatus::INVITED, PortalAccessStatus::DECLINED],
            [PortalAccessStatus::INVITED, PortalAccessStatus::NOT_INVITED],
            [PortalAccessStatus::INVITED, PortalAccessStatus::SUSPENDED],
            [PortalAccessStatus::ACTIVE, PortalAccessStatus::SUSPENDED],
            [PortalAccessStatus::ACTIVE, PortalAccessStatus::DECLINED],
            [PortalAccessStatus::SUSPENDED, PortalAccessStatus::ACTIVE],
            [PortalAccessStatus::SUSPENDED, PortalAccessStatus::DECLINED],
            [PortalAccessStatus::DECLINED, PortalAccessStatus::INVITED],
            [PortalAccessStatus::DECLINED, PortalAccessStatus::NOT_INVITED],
        ];
    }

    /**
     * @dataProvider allowedTransitionsProvider
     */
    public function test_allowed_transitions_do_not_throw(string $from, string $to): void
    {
        $this->policy->assertTransitionAllowed(
            PortalAccessStatus::fromString($from),
            PortalAccessStatus::fromString($to),
        );

        self::assertTrue($this->policy->isTransitionAllowed(
            PortalAccessStatus::fromString($from),
            PortalAccessStatus::fromString($to),
        ));
    }

    /**
     * @return list<array{string, string}>
     */
    public static function disallowedTransitionsProvider(): array
    {
        return [
            [PortalAccessStatus::NOT_INVITED, PortalAccessStatus::ACTIVE],
            [PortalAccessStatus::NOT_INVITED, PortalAccessStatus::SUSPENDED],
            [PortalAccessStatus::ACTIVE, PortalAccessStatus::NOT_INVITED],
            [PortalAccessStatus::ACTIVE, PortalAccessStatus::INVITED],
            [PortalAccessStatus::SUSPENDED, PortalAccessStatus::NOT_INVITED],
            [PortalAccessStatus::SUSPENDED, PortalAccessStatus::INVITED],
            [PortalAccessStatus::DECLINED, PortalAccessStatus::ACTIVE],
            [PortalAccessStatus::DECLINED, PortalAccessStatus::SUSPENDED],
        ];
    }

    /**
     * @dataProvider disallowedTransitionsProvider
     */
    public function test_disallowed_transitions_throw(string $from, string $to): void
    {
        self::assertFalse($this->policy->isTransitionAllowed(
            PortalAccessStatus::fromString($from),
            PortalAccessStatus::fromString($to),
        ));

        $this->expectException(InvalidPortalAccessTransition::class);

        $this->policy->assertTransitionAllowed(
            PortalAccessStatus::fromString($from),
            PortalAccessStatus::fromString($to),
        );
    }

    public function test_same_status_transition_is_a_no_op(): void
    {
        foreach ([
            PortalAccessStatus::notInvited(),
            PortalAccessStatus::invited(),
            PortalAccessStatus::active(),
            PortalAccessStatus::suspended(),
            PortalAccessStatus::declined(),
        ] as $status) {
            self::assertTrue($this->policy->isTransitionAllowed($status, $status));
            $this->policy->assertTransitionAllowed($status, $status);
        }
    }

    public function test_enable_target_returns_invited_by_default(): void
    {
        self::assertTrue($this->policy->enableTarget(false)->equals(PortalAccessStatus::invited()));
    }

    public function test_enable_target_returns_active_when_activating_immediately(): void
    {
        self::assertTrue($this->policy->enableTarget(true)->equals(PortalAccessStatus::active()));
    }

    public function test_disable_target_returns_suspended(): void
    {
        self::assertTrue($this->policy->disableTarget()->equals(PortalAccessStatus::suspended()));
    }
}
