<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Application\DTO;

/**
 * Who may see a report.
 *
 * Creator, current assignee, or anyone recorded on an approval for the report.
 * Approver visibility stays after the decision because the approval row remains.
 */
final readonly class CustomerContactReportVisibility
{
    public function __construct(
        public bool $readAll,
        public int $userId,
        public string $email,
    ) {
    }

    /**
     * @param list<array{userId: ?int, login: string, email: string}> $approvers
     */
    public function allows(
        int $createdByUserId,
        string $createdByEmail,
        ?int $assignedToUserId = null,
        ?string $assignedTo = null,
        ?string $assignedToEmail = null,
        array $approvers = [],
    ): bool {
        if ($this->readAll) {
            return true;
        }

        if ($this->userId > 0 && $createdByUserId === $this->userId) {
            return true;
        }

        $mail = strtolower(trim($this->email));
        if ($mail !== '' && strtolower(trim($createdByEmail)) === $mail) {
            return true;
        }

        if ($this->userId > 0 && $assignedToUserId !== null && $assignedToUserId === $this->userId) {
            return true;
        }

        if ($this->matchesIdentity($mail, $assignedToUserId, $assignedTo, $assignedToEmail)) {
            return true;
        }

        foreach ($approvers as $approver) {
            if ($this->matchesIdentity($mail, $approver['userId'], $approver['login'], $approver['email'])) {
                return true;
            }
        }

        return false;
    }

    private function matchesIdentity(string $mail, ?int $subjectUserId, ?string $login, ?string $email): bool
    {
        if ($this->userId > 0 && $subjectUserId !== null && $subjectUserId === $this->userId) {
            return true;
        }
        if ($mail === '') {
            return false;
        }

        return strtolower(trim($login ?? '')) === $mail || strtolower(trim($email ?? '')) === $mail;
    }
}
