<?php

declare(strict_types=1);

/**
 * Enrich existing ALI-FLEX Marine products BV / DV / PJ / UP from
 * ALIFLEX Marine.pdf (issue 13.0, pages 10–17).
 *
 * Does NOT create products, variants, media, or specification definitions.
 * Creates missing STANDARD options only (IEC 61238-1-1, EN 50363-10-2).
 *
 * Safe to re-run (upserts by product+definition / variant+definition).
 */

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Infrastructure\Database\SqlConnection;

$root = dirname(__DIR__, 3);
$settings = Environment::settings($root);

echo "APP_ENV={$settings->appEnv}\n";
echo "DB_HOST={$settings->dbHost}\n";
echo "DB_DATABASE={$settings->dbDatabase}\n";
echo 'PRODUCTION=' . ($settings->appEnv === 'production' ? 'YES' : 'NO') . "\n";

if ($settings->appEnv === 'production') {
    fwrite(STDERR, "STOP: APP_ENV=production\n");
    exit(1);
}

$c = ContainerFactory::create($settings);
/** @var SqlConnection $db */
$db = $c->get(SqlConnection::class);

$actorUserId = 1;
$pdfSizes = [50.0, 70.0, 95.0, 120.0, 150.0];

$coreColoursBvDvPj = <<<'TEXT'
CENELEC HD 308S2 and IEC 60445
1C: BK
2C: BR BL
3C: BR BK GY
4C: BR BK GY BL
5C: BR BK GY BL EA

AS/NZS 5000.1
1C: WH
2C: RE BK
3C: RE BK EA
4C: RE WH BL EA
5C: RE WH BL BK EA

Other colours available on request.
TEXT;

$coreColoursUp = <<<'TEXT'
WH GY BR RE BK PU PI BL OR EA

Full range of colours available.
Other core colours available on request.
TEXT;

$jacket = "Flame Retardant 'Easy Strip' Low Friction LSZH.\nSplash resistant to oil, skydrol, petrol, acid, sea water.\nResists Ozone and UV.";
$conductors = 'Fine wire EC1350 Aluminium, Class 5, to IEC 60228';
$coreInsulation = "'Easy Strip' LSZH HEPR to IEC 60092-360";
$separator = 'Polypropylene or Polyester Tape';
$braid = 'Tinned Copper Wire (90%) or Galvanized Steel Wire (90%)';

$productsPlan = [
    'BV' => [
        'fullDescription' => 'Flexible Power and Control cables suitable for all shipboard and marine applications.',
        'construction' => [
            'CONDUCTORS' => $conductors,
            'CORE-INSULATION' => $coreInsulation,
            'JACKET' => $jacket,
            'JACKET-OPTION-A' => 'SHF 1 to IEC 60092-360 Thermoplastic',
            'JACKET-OPTION-C' => 'SHF 2 to IEC 60092-360 Thermoset, Oil Resistant',
            'CORE-COLOURS' => $coreColoursBvDvPj,
            'VOLTAGE' => '600/1000 Volts',
            'OXYGEN-INDEX' => '>32',
            'HCL-EMISSION' => '<0.5%',
            'APPROVAL-NOTE' => 'DNV Certificate Pending',
        ],
        'skip' => [],
        'jacketColour' => 'BK',
        'standards' => [
            'IEC 60228', 'IEC 60092-350', 'IEC 60092-360', 'IEC 60092-353',
            'IEC 60332-1-2', 'IEC 60332-3-22', 'IEC 60754-1', 'IEC 60754-2',
            'IEC 61034-1/2', 'IEC 61238-1-1', 'EN 50363-10-2',
        ],
        'tempMin' => -40.0,
        'tempMax' => 90.0,
    ],
    'DV' => [
        'fullDescription' => 'Flexible Power and Control cables suitable for all shipboard and marine applications where mechanical or EMC protection is required.',
        'construction' => [
            'CONDUCTORS' => $conductors,
            'CORE-INSULATION' => $coreInsulation,
            'SEPARATOR' => $separator,
            'BRAID' => $braid,
            'JACKET' => $jacket,
            'JACKET-OPTION-A' => 'SHF 1 to IEC 60092-360 Thermoplastic',
            'JACKET-OPTION-C' => 'SHF 2 to IEC 60092-360 Thermoset, Oil Resistant',
            'CORE-COLOURS' => $coreColoursBvDvPj,
            'VOLTAGE' => '600/1000 Volts',
            'OXYGEN-INDEX' => '>32',
            'HCL-EMISSION' => '<0.5%',
            'APPROVAL-NOTE' => 'DNV Certificate Pending',
        ],
        'skip' => [],
        'jacketColour' => 'BK',
        'standards' => [
            'IEC 60228', 'IEC 60092-350', 'IEC 60092-360', 'IEC 60092-353',
            'IEC 60332-1-2', 'IEC 60332-3-22', 'IEC 60754-1', 'IEC 60754-2',
            'IEC 61034-1/2', 'IEC 61238-1-1', 'EN 50363-10-2',
        ],
        'tempMin' => -40.0,
        'tempMax' => 90.0,
    ],
    'PJ' => [
        'fullDescription' => 'Flexible Power and Control cables suitable for offshore units where mechanical or EMC protection is required.',
        'construction' => [
            'CONDUCTORS' => $conductors,
            'CORE-INSULATION' => $coreInsulation,
            'SEPARATOR' => $separator,
            'BRAID' => $braid,
            'JACKET' => $jacket,
            'JACKET-OPTION-A' => 'SHF 1 to IEC 60092-360 Thermoplastic',
            'JACKET-OPTION-C' => 'SHF 2 to IEC 60092-360 Thermoset, Oil Resistant',
            'CORE-COLOURS' => $coreColoursBvDvPj,
            'VOLTAGE' => '600/1000 Volts',
            'OXYGEN-INDEX' => '>32',
            'HCL-EMISSION' => '<0.5%',
            'APPROVAL-NOTE' => 'DNV Certificate Pending',
        ],
        'skip' => ['BEDDING'],
        'jacketColour' => 'BK',
        'standards' => [
            'IEC 60228', 'IEC 60092-350', 'IEC 60092-360', 'IEC 60092-353',
            'IEC 60332-1-2', 'IEC 60332-3-22', 'IEC 60754-1', 'IEC 60754-2',
            'IEC 61034-1/2', 'IEC 61238-1-1', 'EN 50363-10-2',
        ],
        'tempMin' => -40.0,
        'tempMax' => 90.0,
    ],
    'UP' => [
        'fullDescription' => 'Flexible Power and Control cables suitable for all shipboard and marine applications where mechanical or EMC protection is required.',
        'construction' => [
            'CONDUCTORS' => $conductors,
            'CORE-INSULATION' => $coreInsulation,
            'CORE-COLOURS' => $coreColoursUp,
            'VOLTAGE' => '600/1000 Volts',
            'OXYGEN-INDEX' => '>32',
            'HCL-EMISSION' => '<0.5%',
            'APPROVAL-NOTE' => 'DNV Certificate Pending',
        ],
        'skip' => [],
        'jacketColour' => 'BK',
        'standards' => [
            'IEC 60228', 'IEC 60092-350', 'IEC 60092-360', 'IEC 60092-353',
            'IEC 60332-1-2', 'IEC 60332-3-22', 'IEC 60754-1', 'IEC 60754-2',
            'IEC 61034-1/2', 'IEC 61238-1-1',
        ],
        'tempMin' => -40.0,
        'tempMax' => 90.0,
    ],
];

$pdfRows = [
    'BV' => [
        '1:50' => ['cond' => 10.1, 'current' => 149, 'od' => 15.8, 'bend' => 64, 'weight' => 246],
        '1:70' => ['cond' => 12.1, 'current' => 184, 'od' => 18.3, 'bend' => 74, 'weight' => 334],
        '1:95' => ['cond' => 14.2, 'current' => 223, 'od' => 20.8, 'bend' => 84, 'weight' => 434],
        '1:120' => ['cond' => 16.2, 'current' => 258, 'od' => 22.6, 'bend' => 91, 'weight' => 530],
        '1:150' => ['cond' => 17.8, 'current' => 296, 'od' => 25.1, 'bend' => 101, 'weight' => 648],
        '2:50' => ['cond' => 10.1, 'current' => 127, 'od' => 30.1, 'bend' => 121, 'weight' => 614],
        '2:70' => ['cond' => 12.1, 'current' => 157, 'od' => 35.1, 'bend' => 141, 'weight' => 816],
        '2:95' => ['cond' => 14.2, 'current' => 189, 'od' => 40.0, 'bend' => 160, 'weight' => 1042],
        '2:120' => ['cond' => 16.2, 'current' => 219, 'od' => 43.8, 'bend' => 176, 'weight' => 1311],
        '2:150' => ['cond' => 17.8, 'current' => 252, 'od' => 48.6, 'bend' => 195, 'weight' => 1582],
        '3:50' => ['cond' => 10.1, 'current' => 104, 'od' => 32.3, 'bend' => 130, 'weight' => 763],
        '3:70' => ['cond' => 12.1, 'current' => 128, 'od' => 37.8, 'bend' => 152, 'weight' => 1059],
        '3:95' => ['cond' => 14.2, 'current' => 156, 'od' => 43.1, 'bend' => 173, 'weight' => 1406],
        '3:120' => ['cond' => 16.2, 'current' => 180, 'od' => 47.1, 'bend' => 190, 'weight' => 1712],
        '3:150' => ['cond' => 17.8, 'current' => 207, 'od' => 52.4, 'bend' => 210, 'weight' => 2116],
        '4:50' => ['cond' => 10.1, 'current' => 104, 'od' => 36.0, 'bend' => 144, 'weight' => 1013],
        '4:70' => ['cond' => 12.1, 'current' => 128, 'od' => 42.1, 'bend' => 169, 'weight' => 1377],
        '4:95' => ['cond' => 14.2, 'current' => 156, 'od' => 48.0, 'bend' => 192, 'weight' => 1791],
        '4:120' => ['cond' => 16.2, 'current' => 180, 'od' => 52.5, 'bend' => 210, 'weight' => 2219],
        '4:150' => ['cond' => 17.8, 'current' => 207, 'od' => 58.4, 'bend' => 234, 'weight' => 2714],
        '5:50' => ['cond' => 10.1, 'current' => 104, 'od' => 39.9, 'bend' => 160, 'weight' => 1250],
        '5:70' => ['cond' => 12.1, 'current' => 128, 'od' => 46.8, 'bend' => 188, 'weight' => 1724],
        '5:95' => ['cond' => 14.2, 'current' => 156, 'od' => 53.3, 'bend' => 214, 'weight' => 2240],
        '5:120' => ['cond' => 16.2, 'current' => 180, 'od' => 58.4, 'bend' => 234, 'weight' => 2746],
        '5:150' => ['cond' => 17.8, 'current' => 207, 'od' => 64.9, 'bend' => 260, 'weight' => 3361],
    ],
    'DV' => [
        '1:50' => ['cond' => 10.1, 'current' => 149, 'od' => 18.0, 'bend' => 72, 'weight' => 395],
        '1:70' => ['cond' => 12.1, 'current' => 184, 'od' => 20.5, 'bend' => 82, 'weight' => 503],
        '1:95' => ['cond' => 14.2, 'current' => 223, 'od' => 23.0, 'bend' => 92, 'weight' => 619],
        '1:120' => ['cond' => 16.2, 'current' => 258, 'od' => 24.8, 'bend' => 99, 'weight' => 733],
        '1:150' => ['cond' => 17.8, 'current' => 296, 'od' => 27.3, 'bend' => 109, 'weight' => 872],
        '2:50' => ['cond' => 10.1, 'current' => 127, 'od' => 32.0, 'bend' => 128, 'weight' => 841],
        '2:70' => ['cond' => 12.1, 'current' => 157, 'od' => 37.5, 'bend' => 150, 'weight' => 1104],
        '2:95' => ['cond' => 14.2, 'current' => 189, 'od' => 42.3, 'bend' => 169, 'weight' => 1417],
        '2:120' => ['cond' => 16.2, 'current' => 219, 'od' => 46.1, 'bend' => 184, 'weight' => 1665],
        '2:150' => ['cond' => 17.8, 'current' => 252, 'od' => 50.9, 'bend' => 204, 'weight' => 2003],
        '3:50' => ['cond' => 10.1, 'current' => 104, 'od' => 34.6, 'bend' => 139, 'weight' => 1066],
        '3:70' => ['cond' => 12.1, 'current' => 128, 'od' => 40.1, 'bend' => 161, 'weight' => 1399],
        '3:95' => ['cond' => 14.2, 'current' => 156, 'od' => 45.4, 'bend' => 182, 'weight' => 1762],
        '3:120' => ['cond' => 16.2, 'current' => 180, 'od' => 49.4, 'bend' => 198, 'weight' => 2103],
        '3:150' => ['cond' => 17.8, 'current' => 207, 'od' => 54.7, 'bend' => 219, 'weight' => 2529],
        '4:50' => ['cond' => 10.1, 'current' => 104, 'od' => 37.9, 'bend' => 152, 'weight' => 1295],
        '4:70' => ['cond' => 12.1, 'current' => 128, 'od' => 44.5, 'bend' => 178, 'weight' => 1745],
        '4:95' => ['cond' => 14.2, 'current' => 156, 'od' => 50.3, 'bend' => 201, 'weight' => 2207],
        '4:120' => ['cond' => 16.2, 'current' => 180, 'od' => 54.9, 'bend' => 219, 'weight' => 2666],
        '4:150' => ['cond' => 17.8, 'current' => 207, 'od' => 60.8, 'bend' => 243, 'weight' => 3213],
        '5:50' => ['cond' => 10.1, 'current' => 104, 'od' => 41.9, 'bend' => 167, 'weight' => 1574],
        '5:70' => ['cond' => 12.1, 'current' => 128, 'od' => 49.1, 'bend' => 196, 'weight' => 2130],
        '5:95' => ['cond' => 14.2, 'current' => 156, 'od' => 55.7, 'bend' => 223, 'weight' => 2702],
        '5:120' => ['cond' => 16.2, 'current' => 180, 'od' => 60.7, 'bend' => 243, 'weight' => 3255],
        '5:150' => ['cond' => 17.8, 'current' => 207, 'od' => 67.3, 'bend' => 269, 'weight' => 3926],
    ],
    'PJ' => [
        '1:50' => ['cond' => 10.1, 'current' => 149, 'od' => 20.3, 'bend' => 81, 'weight' => 552],
        '1:70' => ['cond' => 12.1, 'current' => 184, 'od' => 23.0, 'bend' => 92, 'weight' => 695],
        '1:95' => ['cond' => 14.2, 'current' => 223, 'od' => 25.6, 'bend' => 103, 'weight' => 854],
        '1:120' => ['cond' => 16.2, 'current' => 258, 'od' => 27.5, 'bend' => 110, 'weight' => 988],
        '1:150' => ['cond' => 17.8, 'current' => 296, 'od' => 30.1, 'bend' => 120, 'weight' => 1169],
        '2:50' => ['cond' => 10.1, 'current' => 127, 'od' => 37.4, 'bend' => 149, 'weight' => 1183],
        '2:70' => ['cond' => 12.1, 'current' => 157, 'od' => 41.8, 'bend' => 167, 'weight' => 1481],
        '2:95' => ['cond' => 14.2, 'current' => 189, 'od' => 46.6, 'bend' => 186, 'weight' => 1829],
        '2:120' => ['cond' => 16.2, 'current' => 219, 'od' => 51.7, 'bend' => 207, 'weight' => 2212],
        '2:150' => ['cond' => 17.8, 'current' => 252, 'od' => 56.5, 'bend' => 226, 'weight' => 2628],
        '3:50' => ['cond' => 10.1, 'current' => 104, 'od' => 39.7, 'bend' => 159, 'weight' => 1433],
        '3:70' => ['cond' => 12.1, 'current' => 128, 'od' => 44.7, 'bend' => 179, 'weight' => 1852],
        '3:95' => ['cond' => 14.2, 'current' => 156, 'od' => 49.9, 'bend' => 199, 'weight' => 2280],
        '3:120' => ['cond' => 16.2, 'current' => 180, 'od' => 55.2, 'bend' => 221, 'weight' => 2771],
        '3:150' => ['cond' => 17.8, 'current' => 207, 'od' => 60.6, 'bend' => 243, 'weight' => 3323],
        '4:50' => ['cond' => 10.1, 'current' => 104, 'od' => 44.1, 'bend' => 176, 'weight' => 1783],
        '4:70' => ['cond' => 12.1, 'current' => 128, 'od' => 49.3, 'bend' => 197, 'weight' => 2263],
        '4:95' => ['cond' => 14.2, 'current' => 156, 'od' => 55.2, 'bend' => 221, 'weight' => 2847],
        '4:120' => ['cond' => 16.2, 'current' => 180, 'od' => 61.2, 'bend' => 245, 'weight' => 3459],
        '4:150' => ['cond' => 17.8, 'current' => 207, 'od' => 67.1, 'bend' => 269, 'weight' => 4149],
        '5:50' => ['cond' => 10.1, 'current' => 104, 'od' => 48.3, 'bend' => 193, 'weight' => 2111],
        '5:70' => ['cond' => 12.1, 'current' => 128, 'od' => 54.5, 'bend' => 218, 'weight' => 2739],
        '5:95' => ['cond' => 14.2, 'current' => 156, 'od' => 61.0, 'bend' => 244, 'weight' => 3444],
        '5:120' => ['cond' => 16.2, 'current' => 180, 'od' => 67.5, 'bend' => 270, 'weight' => 4183],
        '5:150' => ['cond' => 17.8, 'current' => 207, 'od' => 74.3, 'bend' => 297, 'weight' => 5050],
    ],
    'UP' => [
        '1:50' => ['cond' => 10.1, 'current' => 149, 'od' => 13.1, 'bend' => 53, 'weight' => 179],
        '1:70' => ['cond' => 12.1, 'current' => 184, 'od' => 15.4, 'bend' => 62, 'weight' => 251],
        '1:95' => ['cond' => 14.2, 'current' => 223, 'od' => 17.6, 'bend' => 71, 'weight' => 332],
        '1:120' => ['cond' => 16.2, 'current' => 258, 'od' => 19.4, 'bend' => 78, 'weight' => 416],
        '1:150' => ['cond' => 17.8, 'current' => 296, 'od' => 21.6, 'bend' => 87, 'weight' => 517],
    ],
];

$textTypes = [
    'CONDUCTORS' => 'long-text',
    'CORE-INSULATION' => 'long-text',
    'SEPARATOR' => 'text',
    'BRAID' => 'long-text',
    'JACKET' => 'long-text',
    'JACKET-OPTION-A' => 'text',
    'JACKET-OPTION-C' => 'text',
    'CORE-COLOURS' => 'long-text',
    'VOLTAGE' => 'text',
    'OXYGEN-INDEX' => 'text',
    'HCL-EMISSION' => 'text',
    'APPROVAL-NOTE' => 'long-text',
];

function requireDefinition(SqlConnection $db, string $code): array
{
    $rows = $db->fetchAll(
        <<<'SQL'
SELECT [SpecificationDefinitionId], [DefinitionCode], [DefinitionName], [DataTypeCode], [ScopeCode]
FROM [product].[SpecificationDefinitions]
WHERE [DefinitionCode] = :code AND [DeletedAtUtc] IS NULL
SQL,
        [':code' => $code],
    );
    if ($rows === []) {
        throw new RuntimeException("MISSING_DEFINITION {$code}");
    }

    return $rows[0];
}

function ensureOption(
    SqlConnection $db,
    int $definitionId,
    string $code,
    string $label,
    int $displayOrder,
    int $actorUserId,
): int {
    $existing = $db->fetchAll(
        <<<'SQL'
SELECT [SpecificationOptionId]
FROM [product].[SpecificationOptions]
WHERE [SpecificationDefinitionId] = :definitionId
  AND [OptionCode] = :code
  AND [DeletedAtUtc] IS NULL
SQL,
        [':definitionId' => $definitionId, ':code' => $code],
    );
    if ($existing !== []) {
        return (int) $existing[0]['SpecificationOptionId'];
    }

    $rows = $db->fetchAll(
        <<<'SQL'
INSERT INTO [product].[SpecificationOptions]
    ([SpecificationDefinitionId], [OptionCode], [OptionLabel], [OptionValue], [DisplayOrder], [IsActive], [CreatedByUserId], [UpdatedByUserId])
OUTPUT INSERTED.[SpecificationOptionId]
VALUES (:definitionId, :code, :label, :optionValue, :displayOrder, 1, :createdBy, :updatedBy)
SQL,
        [
            ':definitionId' => $definitionId,
            ':code' => $code,
            ':label' => $label,
            ':optionValue' => $label,
            ':displayOrder' => $displayOrder,
            ':createdBy' => $actorUserId,
            ':updatedBy' => $actorUserId,
        ],
    );
    echo "Created STANDARD option {$code} | {$label}\n";

    return (int) $rows[0]['SpecificationOptionId'];
}

function currentTextValue(SqlConnection $db, int $productId, int $definitionId): ?string
{
    $rows = $db->fetchAll(
        <<<'SQL'
SELECT [ValueText], [ValueLongText]
FROM [product].[ProductSpecificationValues]
WHERE [ProductId] = :productId
  AND [SpecificationDefinitionId] = :definitionId
  AND [DeletedAtUtc] IS NULL
SQL,
        [':productId' => $productId, ':definitionId' => $definitionId],
    );
    if ($rows === []) {
        return null;
    }
    $text = trim((string) ($rows[0]['ValueLongText'] ?? $rows[0]['ValueText'] ?? ''));

    return $text === '' ? '' : $text;
}

function upsertTextValue(
    SqlConnection $db,
    int $productId,
    int $definitionId,
    string $value,
    string $dataType,
    int $actorUserId,
): string {
    $existing = $db->fetchAll(
        <<<'SQL'
SELECT [ProductSpecificationValueId], [ValueText], [ValueLongText]
FROM [product].[ProductSpecificationValues]
WHERE [ProductId] = :productId
  AND [SpecificationDefinitionId] = :definitionId
  AND [DeletedAtUtc] IS NULL
SQL,
        [':productId' => $productId, ':definitionId' => $definitionId],
    );

    $valueText = in_array($dataType, ['text'], true) ? $value : null;
    $valueLongText = in_array($dataType, ['long-text'], true) ? $value : null;
    if ($dataType === 'text' && strlen($value) > 400) {
        $valueText = null;
        $valueLongText = $value;
    }

    $current = null;
    if ($existing !== []) {
        $current = trim((string) ($existing[0]['ValueLongText'] ?? $existing[0]['ValueText'] ?? ''));
        if ($current === $value) {
            return 'KEEP';
        }
    }

    if ($existing === []) {
        $db->execute(
            <<<'SQL'
INSERT INTO [product].[ProductSpecificationValues]
    ([ProductId], [SpecificationDefinitionId], [ValueText], [ValueLongText], [CreatedByUserId], [UpdatedByUserId])
VALUES (:productId, :definitionId, :valueText, :valueLongText, :createdBy, :updatedBy)
SQL,
            [
                ':productId' => $productId,
                ':definitionId' => $definitionId,
                ':valueText' => $valueText,
                ':valueLongText' => $valueLongText,
                ':createdBy' => $actorUserId,
                ':updatedBy' => $actorUserId,
            ],
        );

        return 'ADD';
    }

    $db->execute(
        <<<'SQL'
UPDATE [product].[ProductSpecificationValues]
SET
    [ValueText] = :valueText,
    [ValueLongText] = :valueLongText,
    [SpecificationOptionId] = NULL,
    [UpdatedAtUtc] = SYSUTCDATETIME(),
    [UpdatedByUserId] = :actor,
    [DeletedAtUtc] = NULL
WHERE [ProductSpecificationValueId] = :valueId
SQL,
        [
            ':valueText' => $valueText,
            ':valueLongText' => $valueLongText,
            ':actor' => $actorUserId,
            ':valueId' => (int) $existing[0]['ProductSpecificationValueId'],
        ],
    );

    return $current === null || $current === '' ? 'ADD' : 'UPDATE';
}

function upsertMultiSelectValue(
    SqlConnection $db,
    int $productId,
    int $definitionId,
    array $optionIds,
    int $actorUserId,
): void {
    $existing = $db->fetchAll(
        <<<'SQL'
SELECT [ProductSpecificationValueId]
FROM [product].[ProductSpecificationValues]
WHERE [ProductId] = :productId
  AND [SpecificationDefinitionId] = :definitionId
  AND [DeletedAtUtc] IS NULL
SQL,
        [':productId' => $productId, ':definitionId' => $definitionId],
    );

    if ($existing === []) {
        $rows = $db->fetchAll(
            <<<'SQL'
INSERT INTO [product].[ProductSpecificationValues]
    ([ProductId], [SpecificationDefinitionId], [CreatedByUserId], [UpdatedByUserId])
OUTPUT INSERTED.[ProductSpecificationValueId]
VALUES (:productId, :definitionId, :createdBy, :updatedBy)
SQL,
            [
                ':productId' => $productId,
                ':definitionId' => $definitionId,
                ':createdBy' => $actorUserId,
                ':updatedBy' => $actorUserId,
            ],
        );
        $valueId = (int) $rows[0]['ProductSpecificationValueId'];
    } else {
        $valueId = (int) $existing[0]['ProductSpecificationValueId'];
        $db->execute(
            'UPDATE [product].[ProductSpecificationValues] SET [UpdatedAtUtc] = SYSUTCDATETIME(), [UpdatedByUserId] = :actor WHERE [ProductSpecificationValueId] = :valueId',
            [':actor' => $actorUserId, ':valueId' => $valueId],
        );
        $db->execute(
            'DELETE FROM [product].[ProductSpecificationOptionValues] WHERE [ProductSpecificationValueId] = :valueId',
            [':valueId' => $valueId],
        );
    }

    foreach ($optionIds as $optionId) {
        $db->execute(
            <<<'SQL'
INSERT INTO [product].[ProductSpecificationOptionValues]
    ([ProductSpecificationValueId], [SpecificationOptionId])
VALUES (:valueId, :optionId)
SQL,
            [':valueId' => $valueId, ':optionId' => $optionId],
        );
    }
}

function upsertSelectOption(
    SqlConnection $db,
    int $productId,
    int $definitionId,
    int $optionId,
    int $actorUserId,
): string {
    $existing = $db->fetchAll(
        <<<'SQL'
SELECT [ProductSpecificationValueId], [SpecificationOptionId]
FROM [product].[ProductSpecificationValues]
WHERE [ProductId] = :productId
  AND [SpecificationDefinitionId] = :definitionId
  AND [DeletedAtUtc] IS NULL
SQL,
        [':productId' => $productId, ':definitionId' => $definitionId],
    );
    if ($existing === []) {
        $db->execute(
            <<<'SQL'
INSERT INTO [product].[ProductSpecificationValues]
    ([ProductId], [SpecificationDefinitionId], [SpecificationOptionId], [CreatedByUserId], [UpdatedByUserId])
VALUES (:productId, :definitionId, :optionId, :actor, :actor2)
SQL,
            [
                ':productId' => $productId,
                ':definitionId' => $definitionId,
                ':optionId' => $optionId,
                ':actor' => $actorUserId,
                ':actor2' => $actorUserId,
            ],
        );

        return 'ADD';
    }
    if ((int) $existing[0]['SpecificationOptionId'] === $optionId) {
        return 'KEEP';
    }
    $db->execute(
        <<<'SQL'
UPDATE [product].[ProductSpecificationValues]
SET [SpecificationOptionId] = :optionId,
    [UpdatedAtUtc] = SYSUTCDATETIME(),
    [UpdatedByUserId] = :actor
WHERE [ProductSpecificationValueId] = :valueId
SQL,
        [
            ':optionId' => $optionId,
            ':actor' => $actorUserId,
            ':valueId' => (int) $existing[0]['ProductSpecificationValueId'],
        ],
    );

    return 'UPDATE';
}

function upsertRange(
    SqlConnection $db,
    int $productId,
    int $definitionId,
    float $min,
    float $max,
    int $actorUserId,
): string {
    $existing = $db->fetchAll(
        <<<'SQL'
SELECT [ProductSpecificationValueId], [ValueMinDecimal], [ValueMaxDecimal]
FROM [product].[ProductSpecificationValues]
WHERE [ProductId] = :productId
  AND [SpecificationDefinitionId] = :definitionId
  AND [DeletedAtUtc] IS NULL
SQL,
        [':productId' => $productId, ':definitionId' => $definitionId],
    );
    if ($existing === []) {
        $db->execute(
            <<<'SQL'
INSERT INTO [product].[ProductSpecificationValues]
    ([ProductId], [SpecificationDefinitionId], [ValueMinDecimal], [ValueMaxDecimal], [CreatedByUserId], [UpdatedByUserId])
VALUES (:productId, :definitionId, :minVal, :maxVal, :actor, :actor2)
SQL,
            [
                ':productId' => $productId,
                ':definitionId' => $definitionId,
                ':minVal' => $min,
                ':maxVal' => $max,
                ':actor' => $actorUserId,
                ':actor2' => $actorUserId,
            ],
        );

        return 'ADD';
    }
    $curMin = $existing[0]['ValueMinDecimal'] !== null ? (float) $existing[0]['ValueMinDecimal'] : null;
    $curMax = $existing[0]['ValueMaxDecimal'] !== null ? (float) $existing[0]['ValueMaxDecimal'] : null;
    if ($curMin === $min && $curMax === $max) {
        return 'KEEP';
    }
    $db->execute(
        <<<'SQL'
UPDATE [product].[ProductSpecificationValues]
SET [ValueMinDecimal] = :minVal, [ValueMaxDecimal] = :maxVal,
    [UpdatedAtUtc] = SYSUTCDATETIME(), [UpdatedByUserId] = :actor
WHERE [ProductSpecificationValueId] = :valueId
SQL,
        [
            ':minVal' => $min,
            ':maxVal' => $max,
            ':actor' => $actorUserId,
            ':valueId' => (int) $existing[0]['ProductSpecificationValueId'],
        ],
    );

    return 'UPDATE';
}

function upsertVariantDecimal(
    SqlConnection $db,
    int $variantId,
    int $definitionId,
    float $value,
    int $actorUserId,
): string {
    $existing = $db->fetchAll(
        <<<'SQL'
SELECT [ProductVariantSpecificationValueId], [ValueDecimal]
FROM [product].[ProductVariantSpecificationValues]
WHERE [ProductVariantId] = :variantId
  AND [SpecificationDefinitionId] = :definitionId
  AND [DeletedAtUtc] IS NULL
SQL,
        [':variantId' => $variantId, ':definitionId' => $definitionId],
    );
    if ($existing === []) {
        $db->execute(
            <<<'SQL'
INSERT INTO [product].[ProductVariantSpecificationValues]
    ([ProductVariantId], [SpecificationDefinitionId], [ValueDecimal], [CreatedByUserId], [UpdatedByUserId])
VALUES (:variantId, :definitionId, :valueDecimal, :actor, :actor2)
SQL,
            [
                ':variantId' => $variantId,
                ':definitionId' => $definitionId,
                ':valueDecimal' => $value,
                ':actor' => $actorUserId,
                ':actor2' => $actorUserId,
            ],
        );

        return 'ADD';
    }
    $current = $existing[0]['ValueDecimal'] !== null ? (float) $existing[0]['ValueDecimal'] : null;
    if ($current !== null && abs($current - $value) < 0.0005) {
        return 'KEEP';
    }
    $db->execute(
        <<<'SQL'
UPDATE [product].[ProductVariantSpecificationValues]
SET [ValueDecimal] = :valueDecimal,
    [ValueText] = NULL,
    [UpdatedAtUtc] = SYSUTCDATETIME(),
    [UpdatedByUserId] = :actor
WHERE [ProductVariantSpecificationValueId] = :valueId
SQL,
        [
            ':valueDecimal' => $value,
            ':actor' => $actorUserId,
            ':valueId' => (int) $existing[0]['ProductVariantSpecificationValueId'],
        ],
    );

    return $current === null ? 'ADD' : 'UPDATE';
}

function upsertVariantText(
    SqlConnection $db,
    int $variantId,
    int $definitionId,
    string $value,
    int $actorUserId,
): string {
    $existing = $db->fetchAll(
        <<<'SQL'
SELECT [ProductVariantSpecificationValueId], [ValueText]
FROM [product].[ProductVariantSpecificationValues]
WHERE [ProductVariantId] = :variantId
  AND [SpecificationDefinitionId] = :definitionId
  AND [DeletedAtUtc] IS NULL
SQL,
        [':variantId' => $variantId, ':definitionId' => $definitionId],
    );
    if ($existing === []) {
        $db->execute(
            <<<'SQL'
INSERT INTO [product].[ProductVariantSpecificationValues]
    ([ProductVariantId], [SpecificationDefinitionId], [ValueText], [CreatedByUserId], [UpdatedByUserId])
VALUES (:variantId, :definitionId, :valueText, :actor, :actor2)
SQL,
            [
                ':variantId' => $variantId,
                ':definitionId' => $definitionId,
                ':valueText' => $value,
                ':actor' => $actorUserId,
                ':actor2' => $actorUserId,
            ],
        );

        return 'ADD';
    }
    if ((string) $existing[0]['ValueText'] === $value) {
        return 'KEEP';
    }
    $db->execute(
        <<<'SQL'
UPDATE [product].[ProductVariantSpecificationValues]
SET [ValueText] = :valueText,
    [ValueDecimal] = NULL,
    [UpdatedAtUtc] = SYSUTCDATETIME(),
    [UpdatedByUserId] = :actor
WHERE [ProductVariantSpecificationValueId] = :valueId
SQL,
        [
            ':valueText' => $value,
            ':actor' => $actorUserId,
            ':valueId' => (int) $existing[0]['ProductVariantSpecificationValueId'],
        ],
    );

    return 'UPDATE';
}

function normalizeCsa(mixed $raw): ?float
{
    if ($raw === null) {
        return null;
    }
    $text = trim((string) $raw);
    if ($text === '') {
        return null;
    }
    if (is_numeric($text)) {
        return (float) $text;
    }

    return null;
}

function parseCoresCsa(array $variant): ?array
{
    $cores = $variant['CoreCount'] !== null && $variant['CoreCount'] !== ''
        ? (int) $variant['CoreCount']
        : null;
    $csa = normalizeCsa($variant['CrossSection'] ?? null);

    $haystack = trim((string) ($variant['VariantCode'] ?? '') . ' ' . (string) ($variant['VariantName'] ?? '') . ' ' . (string) ($variant['Sku'] ?? ''));
    if (($cores === null || $cores <= 0 || $csa === null) && preg_match('/(\d+)\s*C\s*(\d+(?:\.\d+)?)/i', $haystack, $m) === 1) {
        $cores = $cores !== null && $cores > 0 ? $cores : (int) $m[1];
        $csa = $csa ?? (float) $m[2];
    }
    if ($cores === null || $cores <= 0 || $csa === null) {
        return null;
    }

    return ['cores' => $cores, 'csa' => $csa];
}

function csaKey(float $csa): string
{
    if (abs($csa - (int) $csa) < 0.0001) {
        return (string) (int) $csa;
    }

    return rtrim(rtrim(sprintf('%.4F', $csa), '0'), '.');
}

function bvaFingerprint(SqlConnection $db): string
{
    $rows = $db->fetchAll(
        <<<'SQL'
SELECT
    p.[FullDescription],
    d.[DefinitionCode],
    v.[ValueText],
    LEFT(v.[ValueLongText], 200) AS [ValueLongText],
    v.[ValueMinDecimal],
    v.[ValueMaxDecimal]
FROM [product].[Products] p
LEFT JOIN [product].[ProductSpecificationValues] v
    ON v.[ProductId] = p.[ProductId] AND v.[DeletedAtUtc] IS NULL
LEFT JOIN [product].[SpecificationDefinitions] d
    ON d.[SpecificationDefinitionId] = v.[SpecificationDefinitionId]
WHERE p.[InternalCode] = N'BVA' AND p.[DeletedAtUtc] IS NULL
ORDER BY d.[DefinitionCode]
SQL,
    );

    return sha1(json_encode($rows, JSON_UNESCAPED_UNICODE));
}

$requiredCodes = [
    'CONDUCTORS', 'CORE-INSULATION', 'SEPARATOR', 'BRAID', 'JACKET',
    'JACKET-OPTION-A', 'JACKET-OPTION-C', 'CORE-COLOURS', 'VOLTAGE',
    'TEMP-RANGE', 'DEFAULT-COLOUR', 'STANDARD', 'APPROVAL-NOTE',
    'OXYGEN-INDEX', 'HCL-EMISSION', 'SIZE', 'MIN-BEND-RADIUS',
    'NOM-COND-OD', 'NOM-CABLE-OD', 'CURRENT', 'APPROX-WEIGHT', 'NOM-INS-OD',
];

$definitionIds = [];
foreach ($requiredCodes as $code) {
    $definitionIds[$code] = (int) requireDefinition($db, $code)['SpecificationDefinitionId'];
}

$missingBedding = $db->fetchAll(
    "SELECT [SpecificationDefinitionId] FROM [product].[SpecificationDefinitions] WHERE [DefinitionCode] = N'BEDDING' AND [DeletedAtUtc] IS NULL",
);
$missingJacketP = $db->fetchAll(
    "SELECT [SpecificationDefinitionId] FROM [product].[SpecificationDefinitions] WHERE [DefinitionCode] = N'JACKET-OPTION-P' AND [DeletedAtUtc] IS NULL",
);
echo 'BEDDING definition: ' . ($missingBedding === [] ? 'MISSING_DEFINITION' : 'FOUND') . "\n";
echo 'JACKET-OPTION-P definition: ' . ($missingJacketP === [] ? 'MISSING_DEFINITION' : 'FOUND') . "\n";

$resolved = [];
foreach (array_keys($productsPlan) as $code) {
    $rows = $db->fetchAll(
        <<<'SQL'
SELECT
    p.[ProductId],
    p.[InternalCode],
    p.[ProductName],
    CONVERT(NVARCHAR(36), p.[PublicId]) AS [PublicId],
    p.[FullDescription]
FROM [product].[Products] p
WHERE p.[InternalCode] = :code
  AND p.[DeletedAtUtc] IS NULL
SQL,
        [':code' => $code],
    );
    echo "CODE={$code} COUNT=" . count($rows) . "\n";
    if (count($rows) !== 1) {
        fwrite(STDERR, "STOP: {$code} resolved to " . count($rows) . " products\n");
        exit(1);
    }
    $resolved[$code] = $rows[0];
    echo "  NAME={$rows[0]['ProductName']} PUBLIC={$rows[0]['PublicId']} FOUND\n";
}

$bvaBefore = bvaFingerprint($db);
echo "BVA fingerprint before={$bvaBefore}\n";

$standardDefId = $definitionIds['STANDARD'];
ensureOption($db, $standardDefId, 'IEC-61238-1-1', 'IEC 61238-1-1', 280, $actorUserId);
ensureOption($db, $standardDefId, 'EN-50363-10-2', 'EN 50363-10-2', 290, $actorUserId);

$standardOptionRows = $db->fetchAll(
    <<<'SQL'
SELECT [SpecificationOptionId], [OptionLabel]
FROM [product].[SpecificationOptions]
WHERE [SpecificationDefinitionId] = :definitionId
  AND [DeletedAtUtc] IS NULL
  AND [IsActive] = 1
SQL,
    [':definitionId' => $standardDefId],
);
$standardByLabel = [];
foreach ($standardOptionRows as $row) {
    $standardByLabel[(string) $row['OptionLabel']] = (int) $row['SpecificationOptionId'];
}

$blackOption = $db->fetchAll(
    <<<'SQL'
SELECT [SpecificationOptionId]
FROM [product].[SpecificationOptions]
WHERE [SpecificationDefinitionId] = :definitionId
  AND [OptionCode] = N'BK'
  AND [DeletedAtUtc] IS NULL
SQL,
    [':definitionId' => $definitionIds['DEFAULT-COLOUR']],
);
if ($blackOption === []) {
    fwrite(STDERR, "STOP: DEFAULT-COLOUR option BK missing\n");
    exit(1);
}
$blackOptionId = (int) $blackOption[0]['SpecificationOptionId'];

$variantMatched = 0;
$variantReview = [];
$fieldActions = [];
$productsCreated = 0;
$variantsCreated = 0;
$mediaModified = 0;

$db->beginTransaction();
try {
    foreach ($productsPlan as $code => $plan) {
        $productId = (int) $resolved[$code]['ProductId'];
        $currentDesc = trim((string) ($resolved[$code]['FullDescription'] ?? ''));
        $pdfDesc = $plan['fullDescription'];
        if ($currentDesc === $pdfDesc) {
            $descAction = 'KEEP';
        } else {
            $db->execute(
                <<<'SQL'
UPDATE [product].[Products]
SET [FullDescription] = :fullDescription,
    [UpdatedAtUtc] = SYSUTCDATETIME(),
    [UpdatedByUserId] = :actor
WHERE [ProductId] = :productId
SQL,
                [
                    ':fullDescription' => $pdfDesc,
                    ':actor' => $actorUserId,
                    ':productId' => $productId,
                ],
            );
            $descAction = $currentDesc === '' ? 'ADD' : 'UPDATE';
            echo "{$code} DESCRIPTION {$descAction}\n";
            echo "  CURRENT: {$currentDesc}\n";
            echo "  PDF: {$pdfDesc}\n";
        }
        $fieldActions[$code]['DESCRIPTION'] = $descAction;

        foreach ($plan['construction'] as $defCode => $value) {
            $action = upsertTextValue(
                $db,
                $productId,
                $definitionIds[$defCode],
                $value,
                $textTypes[$defCode],
                $actorUserId,
            );
            $fieldActions[$code][$defCode] = $action;
            echo "{$code} {$defCode} {$action}\n";
        }

        $tempAction = upsertRange(
            $db,
            $productId,
            $definitionIds['TEMP-RANGE'],
            $plan['tempMin'],
            $plan['tempMax'],
            $actorUserId,
        );
        $fieldActions[$code]['TEMP-RANGE'] = $tempAction;
        echo "{$code} TEMP-RANGE {$tempAction} -40..90\n";

        $colourAction = upsertSelectOption(
            $db,
            $productId,
            $definitionIds['DEFAULT-COLOUR'],
            $blackOptionId,
            $actorUserId,
        );
        $fieldActions[$code]['DEFAULT-COLOUR'] = $colourAction;
        echo "{$code} DEFAULT-COLOUR {$colourAction} BK\n";

        $standardIds = [];
        foreach ($plan['standards'] as $label) {
            if (!isset($standardByLabel[$label])) {
                echo "WARN missing STANDARD option: {$label}\n";
                continue;
            }
            $standardIds[] = $standardByLabel[$label];
        }
        upsertMultiSelectValue($db, $productId, $standardDefId, $standardIds, $actorUserId);
        $fieldActions[$code]['STANDARD'] = 'UPDATE';
        echo "{$code} STANDARD assigned " . count($standardIds) . " options\n";

        $variants = $db->fetchAll(
            <<<'SQL'
SELECT
    pv.[ProductVariantId],
    pv.[VariantCode],
    pv.[Sku],
    pv.[VariantName],
    coreCount.[ValueInteger] AS [CoreCount],
    COALESCE(crossOpt.[OptionCode], crossText.[ValueText], CONVERT(NVARCHAR(50), crossText.[ValueDecimal]), CONVERT(NVARCHAR(50), crossText.[ValueInteger])) AS [CrossSection]
FROM [product].[ProductVariants] pv
LEFT JOIN [product].[ProductVariantSpecificationValues] coreCount
    ON coreCount.[ProductVariantId] = pv.[ProductVariantId]
   AND coreCount.[DeletedAtUtc] IS NULL
   AND coreCount.[SpecificationDefinitionId] = (
        SELECT [SpecificationDefinitionId] FROM [product].[SpecificationDefinitions]
        WHERE [DefinitionCode] = N'CODE_CORE_COUNT' AND [DeletedAtUtc] IS NULL
   )
LEFT JOIN [product].[ProductVariantSpecificationValues] crossText
    ON crossText.[ProductVariantId] = pv.[ProductVariantId]
   AND crossText.[DeletedAtUtc] IS NULL
   AND crossText.[SpecificationDefinitionId] = (
        SELECT [SpecificationDefinitionId] FROM [product].[SpecificationDefinitions]
        WHERE [DefinitionCode] = N'CODE_CROSS_SECTION' AND [DeletedAtUtc] IS NULL
   )
LEFT JOIN [product].[SpecificationOptions] crossOpt
    ON crossOpt.[SpecificationOptionId] = crossText.[SpecificationOptionId]
WHERE pv.[ProductId] = :productId
  AND pv.[DeletedAtUtc] IS NULL
ORDER BY pv.[VariantCode]
SQL,
            [':productId' => $productId],
        );

        $matchedKeys = [];
        foreach ($variants as $variant) {
            $parsed = parseCoresCsa($variant);
            if ($parsed === null) {
                $variantReview[] = "{$code} {$variant['VariantCode']} unparsed VARIANT_MAPPING_REVIEW";
                continue;
            }
            $csa = $parsed['csa'];
            $inPdfSizes = false;
            foreach ($pdfSizes as $size) {
                if (abs($csa - $size) < 0.0001) {
                    $inPdfSizes = true;
                    $csa = $size;
                    break;
                }
            }
            $key = $parsed['cores'] . ':' . csaKey($csa);
            if (!$inPdfSizes || !isset($pdfRows[$code][$key])) {
                $variantReview[] = "{$code} {$variant['VariantCode']} cores={$parsed['cores']} csa={$csa} VARIANT_MAPPING_REVIEW";
                continue;
            }
            $row = $pdfRows[$code][$key];
            $matchedKeys[$key] = true;
            $variantMatched++;
            $variantId = (int) $variant['ProductVariantId'];
            $sizeLabel = sprintf('%dC %smm²', $parsed['cores'], csaKey($csa));
            upsertVariantText($db, $variantId, $definitionIds['SIZE'], $sizeLabel, $actorUserId);
            upsertVariantDecimal($db, $variantId, $definitionIds['NOM-COND-OD'], $row['cond'], $actorUserId);
            upsertVariantDecimal($db, $variantId, $definitionIds['NOM-CABLE-OD'], $row['od'], $actorUserId);
            upsertVariantDecimal($db, $variantId, $definitionIds['MIN-BEND-RADIUS'], (float) $row['bend'], $actorUserId);
            upsertVariantDecimal($db, $variantId, $definitionIds['CURRENT'], (float) $row['current'], $actorUserId);
            upsertVariantDecimal($db, $variantId, $definitionIds['APPROX-WEIGHT'], (float) $row['weight'], $actorUserId);
            if ($code === 'UP') {
                upsertVariantDecimal($db, $variantId, $definitionIds['NOM-INS-OD'], $row['od'], $actorUserId);
            }
            echo "MATCH {$code} {$variant['VariantCode']} {$key}\n";
        }

        foreach (array_keys($pdfRows[$code]) as $key) {
            if (!isset($matchedKeys[$key])) {
                $variantReview[] = "{$code} PDF row {$key} unmatched VARIANT_MAPPING_REVIEW";
            }
        }
    }

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    fwrite(STDERR, 'ROLLBACK: ' . $e->getMessage() . "\n");
    exit(1);
}

$bvaAfter = bvaFingerprint($db);
echo "BVA fingerprint after={$bvaAfter}\n";
echo 'BVA unchanged: ' . ($bvaBefore === $bvaAfter ? 'YES' : 'NO') . "\n";

echo "\nPRODUCTS CREATED={$productsCreated}\n";
echo "VARIANTS CREATED={$variantsCreated}\n";
echo "MEDIA MODIFIED={$mediaModified}\n";
echo "VARIANT ROWS MATCHED={$variantMatched}\n";
echo 'VARIANT ROWS REVIEW=' . count($variantReview) . "\n";
foreach ($variantReview as $line) {
    echo "REVIEW {$line}\n";
}

echo "Done.\n";
