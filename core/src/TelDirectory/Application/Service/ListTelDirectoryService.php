<?php

declare(strict_types=1);

namespace TriCab\Core\TelDirectory\Application\Service;

use TriCab\Core\TelDirectory\Application\DTO\TelDirectoryEntry;
use TriCab\Core\TelDirectory\Application\Exception\TelDirectoryUnavailable;
use TriCab\Core\TelDirectory\Contract\TelDirectoryGraphReader;

final class ListTelDirectoryService
{
    public function __construct(
        private readonly TelDirectoryGraphReader $reader,
        private readonly TelDirectoryMapper $mapper = new TelDirectoryMapper(),
    ) {
    }

    /**
     * @return array{items: list<array<string, mixed>>, count: int}
     */
    public function execute(): array
    {
        if (!$this->reader->isConfigured()) {
            throw TelDirectoryUnavailable::notConfigured();
        }

        try {
            $rows = $this->reader->listActiveDirectoryUsers();
        } catch (TelDirectoryUnavailable $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw TelDirectoryUnavailable::graphFailure();
        }

        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $entry = $this->mapper->map($row);
            if ($entry instanceof TelDirectoryEntry) {
                $items[] = $entry->toArray();
            }
        }

        usort(
            $items,
            static fn (array $a, array $b): int => strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')),
        );

        return [
            'items' => $items,
            'count' => count($items),
        ];
    }
}
