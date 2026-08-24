<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Classification;

/**
 * Semantic classification of WordPress taxonomy terms for Batch A.
 * Only PRODUCT_CATEGORY may become an internal ProductCategory.
 */
final class LegacyTermSemanticClassifier
{
    public function __construct(
        private readonly ClassificationNameNormalizer $normalizer = new ClassificationNameNormalizer(),
    ) {
    }

    /**
     * @param array<string, mixed> $term
     * @return array{
     *   semanticType: string,
     *   reason: string,
     *   confidence: string,
     *   normalizedName: string
     * }
     */
    public function classify(array $term): array
    {
        $raw = (string) ($term['name'] ?? '');
        $decoded = (string) ($term['nameDecoded'] ?? html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $normalized = $this->normalizer->normalize($decoded);
        $prior = (string) ($term['conceptClass'] ?? '');

        if ($normalized === '' || $normalized === 'uncategorized') {
            return $this->result(LegacyTermSemanticType::OBSOLETE, 'empty_or_uncategorized', 'high', $normalized);
        }

        if ($this->isRegion($normalized)) {
            return $this->result(LegacyTermSemanticType::REGION, 'certificate_or_region_term', 'high', $normalized);
        }

        if ($this->isVoltage($decoded, $normalized)) {
            return $this->result(LegacyTermSemanticType::VOLTAGE, 'voltage_rating_or_filter_term', 'high', $normalized);
        }

        if ($this->isObsolete($normalized)) {
            return $this->result(LegacyTermSemanticType::OBSOLETE, 'obsolete_or_non_product_taxonomy', 'high', $normalized);
        }

        if ($this->isApproval($normalized)) {
            return $this->result(LegacyTermSemanticType::APPROVAL, 'certificate_or_approval_taxonomy', 'high', $normalized);
        }

        if ($this->isStandard($decoded, $normalized)) {
            return $this->result(LegacyTermSemanticType::STANDARD, 'standards_taxonomy', 'high', $normalized);
        }

        if ($this->isProductAttribute($decoded, $normalized)) {
            return $this->result(
                LegacyTermSemanticType::PRODUCT_ATTRIBUTE,
                'material_construction_or_performance_attribute',
                'high',
                $normalized,
            );
        }

        if ($this->isCatalogueNavigation($decoded, $normalized, $prior)) {
            return $this->result(
                LegacyTermSemanticType::CATALOGUE_NAVIGATION,
                'customer_navigation_or_product_panel_term',
                'high',
                $normalized,
            );
        }

        if ($this->isApplication($normalized)) {
            return $this->result(LegacyTermSemanticType::APPLICATION, 'application_or_market_segment', 'medium', $normalized);
        }

        if ($this->isIndustry($normalized)) {
            return $this->result(LegacyTermSemanticType::INDUSTRY, 'industry_segment', 'medium', $normalized);
        }

        if ($this->isMarketing($normalized)) {
            return $this->result(LegacyTermSemanticType::MARKETING_GROUP, 'marketing_group_term', 'medium', $normalized);
        }

        if ($this->isLegacyMetadata($normalized, $prior)) {
            return $this->result(LegacyTermSemanticType::LEGACY_METADATA, 'legacy_metadata_not_classification', 'medium', $normalized);
        }

        if ($prior === 'internal_category_candidate' || $prior === '' || $prior === 'unknown') {
            return $this->result(
                LegacyTermSemanticType::PRODUCT_CATEGORY,
                'possible_internal_product_category_candidate',
                'low',
                $normalized,
            );
        }

        return $this->result(LegacyTermSemanticType::UNKNOWN, 'unclassified_requires_operator_review', 'low', $normalized);
    }

    private function isRegion(string $normalized): bool
    {
        return in_array($normalized, [
            'au', 'nz', 'us', 'as', 'australia', 'new zealand', 'united states', 'asia',
        ], true);
    }

    private function isVoltage(string $decoded, string $normalized): bool
    {
        if (str_contains($normalized, 'voltage')) {
            return true;
        }
        if (preg_match('/\d+(\.\d+)?\s*[\/-]?\s*\d*(\.\d+)?\s*(v|kv|volt)/i', $decoded) === 1) {
            return true;
        }
        if (preg_match('/^(low|medium|high)\s+voltage$/i', $decoded) === 1) {
            return true;
        }

        return false;
    }

    private function isObsolete(string $normalized): bool
    {
        return (bool) preg_match('/\b(news|kennards|store|uncategorized|test|zzz)\b/', $normalized);
    }

    private function isCatalogueNavigation(string $decoded, string $normalized, string $prior): bool
    {
        if ($prior === 'customer_navigation') {
            return true;
        }
        $navExact = [
            'accessories',
            'special applications',
            'ali flex',
            'aliflex',
            'plugs and sockets',
            'plugs sockets',
            'products',
            'lugs',
            'heat shrink',
            'cable ties',
            'powersure',
        ];
        if (in_array($normalized, $navExact, true)) {
            return true;
        }

        return (bool) preg_match('/ali-?flex|product panel|catalogue|catalog nav/i', $decoded);
    }

    private function isApplication(string $normalized): bool
    {
        return (bool) preg_match(
            '/\b(marine|solar|mining|construction|building|industrial|telecom|welding|submersible|portable|switchboard|switch board)\b/',
            $normalized,
        );
    }

    private function isIndustry(string $normalized): bool
    {
        return (bool) preg_match('/\b(oil and gas|rail|defence|defense|utilities)\b/', $normalized);
    }

    private function isMarketing(string $normalized): bool
    {
        return (bool) preg_match('/\b(featured|promo|promotion|campaign|bundle)\b/', $normalized);
    }

    private function isApproval(string $normalized): bool
    {
        return (bool) preg_match('/\b(certificate|certificates|approval|approvals|cpt)\b/', $normalized);
    }

    private function isStandard(string $decoded, string $normalized): bool
    {
        if (preg_match('/\b(as\/nzs|iec|ul|nek|en\s?\d|standard|standards)\b/i', $decoded) === 1) {
            return true;
        }

        return (bool) preg_match('/\b(asnzs|iec|nek|standard|standards)\b/', $normalized);
    }

    private function isProductAttribute(string $decoded, string $normalized): bool
    {
        if (preg_match(
            '/\b(copper|aluminium|aluminum|brass|nylon|pvc|xlpe|lszh|tinned|screened|armour|armor|fire resistant|termite|flexible|flexibility|temperature)\b/',
            $normalized,
        ) === 1
            && !preg_match('/\b(cable|gland|connector|terminal|lug|conduit|ferrule)\b/', $normalized)
        ) {
            return true;
        }

        if (preg_match('/\b(ring and fork|pin and blade|core count|cores?|conductor)\b/', $normalized) === 1) {
            return true;
        }

        return preg_match('/fire resistant|termite resistant/i', $decoded) === 1
            && !preg_match('/cable|conduit/i', $decoded);
    }

    private function isLegacyMetadata(string $normalized, string $prior): bool
    {
        if ($prior === 'certificate_region') {
            return false;
        }

        return (bool) preg_match('/\b(tag|meta)\b/', $normalized);
    }

    /**
     * @return array{semanticType: string, reason: string, confidence: string, normalizedName: string}
     */
    private function result(string $type, string $reason, string $confidence, string $normalized): array
    {
        return [
            'semanticType' => $type,
            'reason' => $reason,
            'confidence' => $confidence,
            'normalizedName' => $normalized,
        ];
    }
}
