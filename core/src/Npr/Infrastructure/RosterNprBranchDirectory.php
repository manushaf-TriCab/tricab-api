<?php

declare(strict_types=1);

namespace TriCab\Core\Npr\Infrastructure;

use TriCab\Core\Npr\Contract\NprBranchDirectory;

/**
 * Branch by email from the npr-portal's sales roster (`resources/npr/sales-roster.json`).
 * The roster is not authorisation, only a branch lookup; a missing file, a person
 * not in it or one without a branch is 'unassigned'.
 */
final class RosterNprBranchDirectory implements NprBranchDirectory
{
    /** @var array<string, string>|null */
    private ?array $byEmail = null;

    public function __construct(private readonly string $rosterPath)
    {
    }

    public function branchForEmail(string $email): string
    {
        return $this->roster()[strtolower(trim($email))] ?? self::UNASSIGNED;
    }

    /** @return array<string, string> */
    private function roster(): array
    {
        if ($this->byEmail !== null) {
            return $this->byEmail;
        }
        $this->byEmail = [];
        $raw = is_file($this->rosterPath) ? file_get_contents($this->rosterPath) : false;
        $decoded = $raw === false ? null : json_decode($raw, true);
        $people = is_array($decoded) && is_array($decoded['people'] ?? null) ? $decoded['people'] : [];
        foreach ($people as $person) {
            if (!is_array($person)) {
                continue;
            }
            $email = $person['email'] ?? null;
            $branch = $person['branch'] ?? null;
            if (is_string($email) && is_string($branch) && trim($branch) !== '') {
                $this->byEmail[strtolower(trim($email))] = trim($branch);
            }
        }

        return $this->byEmail;
    }
}
