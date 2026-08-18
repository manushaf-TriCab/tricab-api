<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Application\DTO;

final class MigrationIssueDetails
{
    /**
     * @param array<string, mixed>|null $suggestedMatch
     * @param array<string, mixed>|null $resolution
     */
    public function __construct(
        public readonly string $publicId,
        public readonly string $batchPublicId,
        public readonly string $issueTypeCode,
        public readonly string $severityCode,
        public readonly ?string $sourceSystem,
        public readonly ?string $sourceTable,
        public readonly ?string $sourceIdentifier,
        public readonly ?string $sourceKey,
        public readonly string $message,
        public readonly ?array $suggestedMatch,
        public readonly ?string $matchConfidence,
        public readonly string $statusCode,
        public readonly ?array $resolution,
        public readonly string $createdAtUtc,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'publicId' => $this->publicId,
            'batchPublicId' => $this->batchPublicId,
            'issueTypeCode' => $this->issueTypeCode,
            'severityCode' => $this->severityCode,
            'sourceSystem' => $this->sourceSystem,
            'sourceTable' => $this->sourceTable,
            'sourceIdentifier' => $this->sourceIdentifier,
            'sourceKey' => $this->sourceKey,
            'message' => $this->message,
            'suggestedMatch' => $this->suggestedMatch,
            'matchConfidence' => $this->matchConfidence,
            'statusCode' => $this->statusCode,
            'resolution' => $this->resolution,
            'createdAtUtc' => $this->createdAtUtc,
        ];
    }
}
