<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\Plugin;

use TriCab\Core\LegacyMigration\Contract\Normalizer;
use TriCab\Core\LegacyMigration\Support\NormalizedRecord;
use TriCab\Core\LegacyMigration\Support\RawRecord;

final class CanonicalNormalizer implements Normalizer
{
    public function __construct(private readonly string $moduleCode)
    {
    }

    public function normalize(RawRecord $raw): NormalizedRecord
    {
        $canonical = [];
        foreach ($raw->payload as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            if (\TriCab\Core\LegacyMigration\Support\CredentialRedactor::isForbidden($key)) {
                continue;
            }
            $canonical[$key] = $value;
        }

        return new NormalizedRecord(
            sourceIdentifier: $raw->sourceIdentifier,
            moduleCode: $this->moduleCode,
            canonical: $canonical,
            rawPayload: $canonical,
            sourceHash: $raw->sourceHash(),
        );
    }
}
