<?php

declare(strict_types=1);

use TriCab\Core\Identity\Application\DTO\VerifiedIdentity;
use TriCab\Core\Identity\Application\Handler\ResolveAuthenticatedUserHandler;
use TriCab\Core\DocumentGeneration\Application\Handler\GenerateOcrPdfHandler;
use TriCab\Core\DocumentGeneration\Contract\PdfRenderer;
use TriCab\Core\Shared\Package;

require dirname(__DIR__) . '/vendor/autoload.php';

if (Package::NAME !== 'tricab/tricab-core') {
    fwrite(STDERR, "Unexpected Core package name: " . Package::NAME . PHP_EOL);
    exit(1);
}

echo 'OK: imported ' . VerifiedIdentity::class . PHP_EOL;
echo 'OK: imported ' . ResolveAuthenticatedUserHandler::class . PHP_EOL;
echo 'OK: imported ' . GenerateOcrPdfHandler::class . PHP_EOL;
echo 'OK: imported ' . PdfRenderer::class . PHP_EOL;
echo 'OK: Core package ' . Package::NAME . PHP_EOL;
