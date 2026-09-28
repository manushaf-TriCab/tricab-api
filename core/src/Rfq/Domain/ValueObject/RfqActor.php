<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Domain\ValueObject;

/**
 * The person an RFQ request is acting for, as the server resolved them.
 *
 * Built from the authenticated platform user, once per request, by
 * `ResolveRfqActorService`. There is no constructor path from a request body:
 * the source's `__rfq_user` blob — which the PHP bridge had to strip from the
 * client payload and rewrite — has no equivalent here.
 *
 * `branchCode` is null until platform identity carries branch membership
 * (owner question Q7). It never limits what the actor sees.
 */
final class RfqActor
{
    /** @var array<string, true> upper-cased permission codes */
    private readonly array $permissions;

    /**
     * @param list<string> $permissions the platform permission codes the user holds
     */
    public function __construct(
        public readonly int $userId,
        public readonly string $userPublicId,
        array $permissions,
        public readonly ?string $branchCode,
    ) {
        $held = [];
        foreach ($permissions as $permission) {
            $held[strtoupper(trim($permission))] = true;
        }
        $this->permissions = $held;
    }

    public function can(string $permission): bool
    {
        return isset($this->permissions[strtoupper($permission)]);
    }

    /**
     * The RFQ.* codes this actor holds, sorted, for the access summary.
     *
     * @return list<string>
     */
    public function rfqPermissions(): array
    {
        $codes = array_values(array_filter(
            array_keys($this->permissions),
            static fn (string $code): bool => str_starts_with($code, 'RFQ.'),
        ));
        sort($codes);

        return $codes;
    }
}
