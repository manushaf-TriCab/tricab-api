<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Migration\Plan;

/**
 * What the preview found, record by record (Phase 7.3). Three severities:
 *
 *  - **blocking** — the snapshot is not internally sound (a record naming one
 *    that is not there, an address that is not one). The batch cannot be
 *    approved until the source is fixed and re-extracted.
 *  - **warning** — a record is skipped, or carried with a difference the
 *    operator should see before approving.
 *  - **info** — worth knowing; changes nothing.
 *
 * @internal
 */
final class Findings
{
    public const BLOCKING = 'blocking';
    public const WARNING = 'warning';
    public const INFO = 'info';

    /** @var list<array{severity: string, code: string, subject: string, message: string}> */
    private array $all = [];

    public function blocking(string $code, string $subject, string $message): void
    {
        $this->add(self::BLOCKING, $code, $subject, $message);
    }

    public function warning(string $code, string $subject, string $message): void
    {
        $this->add(self::WARNING, $code, $subject, $message);
    }

    public function info(string $code, string $subject, string $message): void
    {
        $this->add(self::INFO, $code, $subject, $message);
    }

    /** @return list<array{severity: string, code: string, subject: string, message: string}> */
    public function all(): array
    {
        return $this->all;
    }

    private function add(string $severity, string $code, string $subject, string $message): void
    {
        $this->all[] = ['severity' => $severity, 'code' => $code, 'subject' => $subject, 'message' => $subject . ': ' . $message];
    }
}
