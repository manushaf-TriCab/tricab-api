<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Storage;

/**
 * Account-key SAS helpers for Azure Blob / Azurite.
 * Production should prefer Managed Identity + user-delegation SAS;
 * this issuer is the approved connection-string / Azurite path for
 * non-production and local development.
 *
 * Never expose the account key to the frontend.
 */
final class AzureBlobAccountSasIssuer
{
    public function __construct(
        private readonly string $accountName,
        private readonly string $accountKey,
        private readonly string $blobEndpoint,
    ) {
    }

    /**
     * @param array<string, string> $headers
     * @return array{url: string, headers: array<string, string>, expiresAtUtc: string}
     */
    public function issueBlobWriteSas(
        string $container,
        string $blobPath,
        string $contentType,
        int $expiresInSeconds,
    ): array {
        $expiresInSeconds = max(60, min($expiresInSeconds, 3600));
        $signedStart = gmdate('Y-m-d\TH:i:s\Z', time() - 60);
        $signedExpiry = gmdate('Y-m-d\TH:i:s\Z', time() + $expiresInSeconds);
        $canonicalizedResource = '/blob/' . $this->accountName . '/' . $container . '/' . ltrim($blobPath, '/');

        // Service SAS for a single blob: sp=cw (create+write), sr=b
        $signedPermissions = 'cw';
        $signedResource = 'b';
        $signedProtocol = 'https';
        $signedVersion = '2022-11-02';

        // String-to-sign for Blob service SAS (API version >= 2020-12-06)
        $stringToSign = implode("\n", [
            $signedPermissions,
            $signedStart,
            $signedExpiry,
            $canonicalizedResource,
            '', // signedIdentifier
            '', // signedIP
            $signedProtocol,
            $signedVersion,
            $signedResource,
            '', // signedSnapshotTime
            '', // signedEncryptionScope
            '', // rscc
            '', // rscd
            '', // rsce
            '', // rscl
            $contentType !== '' ? $contentType : '', // rsct — match required Content-Type header
        ]);

        $signature = base64_encode(hash_hmac(
            'sha256',
            $stringToSign,
            base64_decode($this->accountKey, true) ?: '',
            true,
        ));

        $query = http_build_query([
            'sv' => $signedVersion,
            'ss' => 'b',
            'srt' => 'o',
            'sp' => $signedPermissions,
            'se' => $signedExpiry,
            'st' => $signedStart,
            'spr' => $signedProtocol,
            'sr' => $signedResource,
            'sig' => $signature,
            'rsct' => $contentType,
        ], '', '&', PHP_QUERY_RFC3986);

        // Azurite and Azure expect path-style: {endpoint}/{container}/{blob}
        $url = rtrim($this->blobEndpoint, '/') . '/' . rawurlencode($container) . '/' . $this->encodeBlobPath($blobPath) . '?' . $query;

        return [
            'url' => $url,
            'headers' => [
                'Content-Type' => $contentType,
                'x-ms-blob-type' => 'BlockBlob',
            ],
            'expiresAtUtc' => $signedExpiry,
        ];
    }

    /**
     * @return array{url: string, headers: array<string, string>, expiresAtUtc: string}
     */
    public function issueBlobReadSas(
        string $container,
        string $blobPath,
        int $expiresInSeconds,
    ): array {
        $expiresInSeconds = max(60, min($expiresInSeconds, 3600));
        $signedStart = gmdate('Y-m-d\TH:i:s\Z', time() - 60);
        $signedExpiry = gmdate('Y-m-d\TH:i:s\Z', time() + $expiresInSeconds);
        $canonicalizedResource = '/blob/' . $this->accountName . '/' . $container . '/' . ltrim($blobPath, '/');

        $signedPermissions = 'r';
        $signedResource = 'b';
        $signedProtocol = 'https';
        $signedVersion = '2022-11-02';

        $stringToSign = implode("\n", [
            $signedPermissions,
            $signedStart,
            $signedExpiry,
            $canonicalizedResource,
            '',
            '',
            $signedProtocol,
            $signedVersion,
            $signedResource,
            '',
            '',
            '',
            '',
            '',
            '',
            '',
        ]);

        $signature = base64_encode(hash_hmac(
            'sha256',
            $stringToSign,
            base64_decode($this->accountKey, true) ?: '',
            true,
        ));

        $query = http_build_query([
            'sv' => $signedVersion,
            'ss' => 'b',
            'srt' => 'o',
            'sp' => $signedPermissions,
            'se' => $signedExpiry,
            'st' => $signedStart,
            'spr' => $signedProtocol,
            'sr' => $signedResource,
            'sig' => $signature,
        ], '', '&', PHP_QUERY_RFC3986);

        $url = rtrim($this->blobEndpoint, '/') . '/' . rawurlencode($container) . '/' . $this->encodeBlobPath($blobPath) . '?' . $query;

        return [
            'url' => $url,
            'headers' => [],
            'expiresAtUtc' => $signedExpiry,
        ];
    }

    private function encodeBlobPath(string $blobPath): string
    {
        $parts = explode('/', ltrim($blobPath, '/'));

        return implode('/', array_map(static fn (string $part): string => rawurlencode($part), $parts));
    }
}
