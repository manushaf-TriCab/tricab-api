<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Application\DTO;

final class DryRunReport
{
    /**
     * @param array<string, int> $counts
     * @param list<string> $warnings
     * @param list<string> $blockingErrors
     * @param list<array<string, mixed>> $unresolved
     */
    public function __construct(
        public readonly string $batchPublicId,
        public readonly array $counts,
        public readonly array $warnings,
        public readonly array $blockingErrors,
        public readonly array $unresolved,
        public readonly bool $wouldWriteProducts = false,
        public readonly bool $wouldWriteBlobs = false,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'batchPublicId' => $this->batchPublicId,
            'counts' => $this->counts,
            'warnings' => $this->warnings,
            'blockingErrors' => $this->blockingErrors,
            'unresolved' => $this->unresolved,
            'wouldWriteProducts' => $this->wouldWriteProducts,
            'wouldWriteBlobs' => $this->wouldWriteBlobs,
            'dryRunNoWriteGuarantee' => !$this->wouldWriteProducts && !$this->wouldWriteBlobs,
        ];
    }

    public function toCsv(): string
    {
        $lines = ['metric,value'];
        foreach ($this->counts as $k => $v) {
            $lines[] = $k . ',' . $v;
        }
        $lines[] = 'warnings,' . count($this->warnings);
        $lines[] = 'blockingErrors,' . count($this->blockingErrors);

        return implode("\n", $lines) . "\n";
    }
}
