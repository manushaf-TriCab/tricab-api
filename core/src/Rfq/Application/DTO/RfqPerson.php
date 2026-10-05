<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Application\DTO;

/**
 * An active platform user as the workflow panel's owner picker knows them.
 *
 * `email` is for the server alone: the store names people by address, so
 * assigning has to resolve an id to one. It is never serialised
 * ({@see toOption()} is the only projection), because emails do not leave the
 * server (invariant 11).
 */
final class RfqPerson
{
    public function __construct(
        public readonly string $publicId,
        public readonly string $name,
        public readonly string $email,
    ) {
    }

    /**
     * @return array{id: string, name: string}
     */
    public function toOption(): array
    {
        return ['id' => $this->publicId, 'name' => $this->name];
    }
}
