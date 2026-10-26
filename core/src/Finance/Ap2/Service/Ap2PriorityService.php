<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Service;

use TriCab\Core\Finance\Ap2\Contract\Ap2PriorityStore;
use TriCab\Core\Finance\Ap2\Domain\Ap2Actor;
use TriCab\Core\Finance\Ap2\Domain\Ap2Failure;
use TriCab\Core\Finance\Ap2\Domain\Ap2Invalid;

/** A person's own flags and pins. Nobody else's are readable or writable. */
final class Ap2PriorityService
{
    public const KINDS = ['invoice', 'supplier', 'mail', 'approval', 'payment'];

    public function __construct(private readonly Ap2PriorityStore $store)
    {
    }

    /** @return array<string, array{flag: bool, pin: bool}> keyed `kind:id` */
    public function all(Ap2Actor $actor): array
    {
        return $this->store->forUser($actor->userId);
    }

    /**
     * @param array<string, mixed> $body `flag` and/or `pin`; one left out keeps its value
     * @return array{flag: bool, pin: bool}
     */
    public function put(Ap2Actor $actor, string $kind, string $id, array $body): array
    {
        $issues = [];
        if (!in_array($kind, self::KINDS, true)) {
            $issues[] = 'kind must be one of ' . implode(', ', self::KINDS);
        }
        if ($id === '' || strlen($id) > 40) {
            $issues[] = 'id is invalid';
        }
        $input = new Ap2Input($body);
        $input->flag('flag');
        $input->flag('pin');
        $issues = [...$issues, ...$input->issues()];
        if ($issues !== []) {
            throw new Ap2Invalid('The priority is invalid', Ap2Failure::details($issues));
        }
        $current = $this->store->forUser($actor->userId)[$kind . ':' . $id] ?? ['flag' => false, 'pin' => false];
        $values = $input->values();
        $flag = is_bool($values['flag'] ?? null) ? $values['flag'] : $current['flag'];
        $pin = is_bool($values['pin'] ?? null) ? $values['pin'] : $current['pin'];
        $this->store->put($actor->userId, $kind, $id, $flag, $pin);

        return ['flag' => $flag, 'pin' => $pin];
    }
}
