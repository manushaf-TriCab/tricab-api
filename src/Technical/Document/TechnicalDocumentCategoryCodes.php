<?php

declare(strict_types=1);

namespace TriCab\Api\Technical\Document;

/**
 * Stable Technical document family codes (IA / catalog keys).
 */
final class TechnicalDocumentCategoryCodes
{
    public const WARRANTY = 'warranty';
    public const BEP_PVC_DISPOSAL = 'bep-pvc-disposal';
    public const BEP_PVC_CERTIFICATE = 'bep-pvc-certificate';
    public const INDUSTRIAL_CERTS = 'industrial-certs';
    public const SBO_CERTS = 'sbo-certs';
    public const ACCESSORY_CERTS = 'accessory-certs';
    public const ACCESSORY_WARRANTY = 'accessory-warranty';
    public const INSTALLATION_GUIDES = 'installation-guides';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::WARRANTY,
            self::BEP_PVC_DISPOSAL,
            self::BEP_PVC_CERTIFICATE,
            self::INDUSTRIAL_CERTS,
            self::SBO_CERTS,
            self::ACCESSORY_CERTS,
            self::ACCESSORY_WARRANTY,
            self::INSTALLATION_GUIDES,
        ];
    }
}
