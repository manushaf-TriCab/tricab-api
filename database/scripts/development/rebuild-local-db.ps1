<#
================================================================================
  Rebuilds the local development database in Docker from scratch.

  WHY THIS EXISTS
    Azure SQL serverless bills max(vCores used, min vCores) x seconds ONLINE,
    not per query. At the default 0.5 min vCores the free grant of 100,000
    vCore-seconds is only ~55 hours of uptime per month (~1.8 h/day), and the
    auto-pause delay has a 60-minute floor -- so a single query bills a full
    hour. Developing against Azure exhausts the grant in about six working
    days. Develop here instead; use Azure for verification passes only.

  USAGE
    powershell -File database/scripts/development/rebuild-local-db.ps1
    powershell -File database/scripts/development/rebuild-local-db.ps1 -Drop

  REQUIRES
    Docker, sqlcmd on PATH, php with pdo_sqlsrv, python.

  SCRIPT ORDER NOTES (learned the hard way -- do not reorder blindly)
    * create-customer-schema.sql must run BEFORE create-product-schema.sql:
      product.Products has an FK to customer.Customers.
    * create-product-migration-schema.sql runs WITHOUT -b: it re-creates
      objects create-product-schema.sql already made and its CREATE INDEX
      statements have no IF NOT EXISTS guard.
    * seed-product-cable-reference.sql must run AFTER
      seed-product-catalogue-sample.sql (needs the STANDARD definition).
    * catalog-load/sql/34 and /35 hardcode Azure's IDENTITY values for
      product.Products and are NOT portable. This script regenerates them from
      the Python builders against local ids, in a temp directory, so the
      checked-in Azure-targeted copies are never overwritten.
================================================================================
#>

[CmdletBinding()]
param(
    [switch]$Drop,
    [string]$ContainerName = 'tricab-mssql',
    [string]$SaPassword    = 'TriCab!Local2026',
    [string]$Database      = 'tc'
)

$ErrorActionPreference = 'Stop'

$RepoRoot    = (Resolve-Path (Join-Path $PSScriptRoot '..\..\..')).Path
$DevScripts  = $PSScriptRoot
$CatalogLoad = (Resolve-Path (Join-Path $RepoRoot '..\catalog-load')).Path
$Server      = 'localhost,1433'

function Invoke-SqlFile {
    param([string]$Path, [switch]$ContinueOnError)

    $name = Split-Path $Path -Leaf
    $args = @('-S', $Server, '-U', 'sa', '-P', $SaPassword, '-C', '-d', $Database, '-i', $Path)
    if (-not $ContinueOnError) { $args += '-b' }

    $log = Join-Path $env:TEMP "rebuild-local-db.$name.log"
    & sqlcmd @args | Out-File -Encoding utf8 $log
    if ($LASTEXITCODE -ne 0 -and -not $ContinueOnError) {
        Write-Host "  FAIL  $name" -ForegroundColor Red
        Get-Content $log | Select-Object -First 10 | ForEach-Object { "        $_" }
        throw "$name failed. Full log: $log"
    }
    Write-Host "  ok    $name" -ForegroundColor DarkGray
}

function Invoke-PhpSeeder {
    param([string]$Path)

    $name = Split-Path $Path -Leaf
    # Settings reads $_ENV, which PHP CLI does not populate without this.
    & php -d variables_order=EGPCS $Path | Out-Null
    if ($LASTEXITCODE -ne 0) { throw "$name failed (exit $LASTEXITCODE)" }
    Write-Host "  ok    $name" -ForegroundColor DarkGray
}

# --- container -------------------------------------------------------------
Write-Host "`n[1/6] Container" -ForegroundColor Cyan
$exists = (& docker ps -a --filter "name=^/$ContainerName$" --format '{{.Names}}')
if (-not $exists) {
    Write-Host "  creating $ContainerName"
    & docker run -d --name $ContainerName `
        -e 'ACCEPT_EULA=Y' -e "MSSQL_SA_PASSWORD=$SaPassword" -e 'MSSQL_PID=Developer' `
        -p 1433:1433 -v "$ContainerName-data:/var/opt/mssql" `
        mcr.microsoft.com/mssql/server:2022-latest | Out-Null
} else {
    & docker start $ContainerName | Out-Null
    Write-Host "  started $ContainerName"
}

Write-Host "  waiting for SQL Server..."
$ready = $false
foreach ($i in 1..60) {
    & sqlcmd -S $Server -U sa -P $SaPassword -C -Q 'SELECT 1' 2>&1 | Out-Null
    if ($LASTEXITCODE -eq 0) { $ready = $true; break }
    Start-Sleep -Seconds 2
}
if (-not $ready) { throw 'SQL Server did not become ready in 120s.' }

# --- database --------------------------------------------------------------
Write-Host "`n[2/6] Database [$Database]" -ForegroundColor Cyan
if ($Drop) {
    Write-Host "  dropping existing [$Database]" -ForegroundColor Yellow
    & sqlcmd -S $Server -U sa -P $SaPassword -C -b -Q `
        "IF DB_ID('$Database') IS NOT NULL BEGIN ALTER DATABASE [$Database] SET SINGLE_USER WITH ROLLBACK IMMEDIATE; DROP DATABASE [$Database]; END"
    if ($LASTEXITCODE -ne 0) { throw 'drop failed' }
}
& sqlcmd -S $Server -U sa -P $SaPassword -C -b -Q "IF DB_ID('$Database') IS NULL CREATE DATABASE [$Database];"
if ($LASTEXITCODE -ne 0) { throw 'create database failed' }

# --- schema ----------------------------------------------------------------
Write-Host "`n[3/6] Schema" -ForegroundColor Cyan
Invoke-SqlFile (Join-Path $DevScripts 'create-identity-schema.sql')
Invoke-SqlFile (Join-Path $DevScripts 'create-customer-schema.sql')          # before product
Invoke-SqlFile (Join-Path $DevScripts 'create-product-schema.sql')
Invoke-SqlFile (Join-Path $DevScripts 'create-product-construction-schema.sql')
Invoke-SqlFile (Join-Path $DevScripts 'create-product-migration-schema.sql') -ContinueOnError
Invoke-SqlFile (Join-Path $DevScripts 'create-legacy-migration-schema.sql')
Invoke-SqlFile (Join-Path $DevScripts 'create-site-configurations.sql')
Invoke-SqlFile (Join-Path $DevScripts 'create-user-management-audit.sql')
Invoke-SqlFile (Join-Path $DevScripts 'create-identity-user-email-addresses.sql')
Invoke-SqlFile (Join-Path $DevScripts 'create-onboarding-schema.sql')        # after identity + audit
Invoke-SqlFile (Join-Path $DevScripts 'create-onboarding-hr-schema.sql')     # after onboarding: FKs point back into it
Invoke-SqlFile (Join-Path $DevScripts 'create-onboarding-admin-audit.sql')   # after identity: FKs to identity.Users
Invoke-SqlFile (Join-Path $DevScripts 'alter-onboarding-policy-metadata.sql') # after onboarding: adds columns to Policies, PolicyVersions
Invoke-SqlFile (Join-Path $DevScripts 'alter-product-file-upload-phase9.sql')

# --- seeds -----------------------------------------------------------------
Write-Host "`n[4/6] Reference data and seeds" -ForegroundColor Cyan
foreach ($s in @(
    'seed-product-reference-data.sql',
    'seed-user-management-permissions.sql',
    'seed-legacy-migration-permission.sql',
    'seed-product-roles-and-permissions.sql',
    'alter-product-regions.sql',
    'seed-customer-roles-and-permissions.sql',
    'seed-guest-role-and-login-permission.sql',
    'seed-onboarding-permissions.sql',   # after guest role: grants GUEST + EMPLOYEE self-service
    'seed-onboarding-roles.sql',         # after onboarding permissions: ONBOARDING_ADMIN
    'seed-product-catalogue-sample.sql',
    'seed-product-cable-reference.sql',      # after catalogue-sample
    'seed-customer-sample-data.sql'
)) { Invoke-SqlFile (Join-Path $DevScripts $s) }

# --- catalogue -------------------------------------------------------------
Write-Host "`n[5/6] Catalogue load" -ForegroundColor Cyan
foreach ($s in @(
    '30_azure_product_load.sql',
    '31_azure_standards_seed.sql',
    '32_azure_product_coding_seed.sql'
)) { Invoke-SqlFile (Join-Path $CatalogLoad "sql\$s") }

Invoke-PhpSeeder (Join-Path $DevScripts 'seed-technical-dimensions.php')
Invoke-PhpSeeder (Join-Path $DevScripts 'seed-bva-catalogue.php')
Invoke-PhpSeeder (Join-Path $DevScripts 'seed-bh-construction-catalogue.php')

# --- variants (regenerated for local ids) -----------------------------------
Write-Host "`n[6/6] Variants (regenerating for local ProductIds)" -ForegroundColor Cyan
$work = Join-Path $env:TEMP "tricab-catalog-local-$PID"
New-Item -ItemType Directory -Force -Path (Join-Path $work 'review') | Out-Null
New-Item -ItemType Directory -Force -Path (Join-Path $work 'sql')    | Out-Null
Copy-Item (Join-Path $CatalogLoad 'build_azure_variant_load.py')       $work
Copy-Item (Join-Path $CatalogLoad 'build_azure_variant_specs_load.py') $work

& sqlcmd -S $Server -U sa -P $SaPassword -C -d $Database -h -1 -W -s ',' `
    -Q "SET NOCOUNT ON; SELECT ProductId, InternalCode FROM product.Products WHERE InternalCode IS NOT NULL AND DeletedAtUtc IS NULL ORDER BY InternalCode;" `
    -o (Join-Path $work 'review\_products_live.csv')
if ($LASTEXITCODE -ne 0) { throw 'product id export failed' }

Push-Location $work
try {
    & python build_azure_variant_load.py       | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'build_azure_variant_load.py failed' }
    & python build_azure_variant_specs_load.py | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'build_azure_variant_specs_load.py failed' }
} finally {
    Pop-Location
}

Invoke-SqlFile (Join-Path $work 'sql\34_azure_variant_load.sql')
Invoke-SqlFile (Join-Path $work 'sql\35_azure_variant_specs_load.sql')
Remove-Item -Recurse -Force $work -ErrorAction SilentlyContinue

# --- summary ---------------------------------------------------------------
Write-Host "`nDone." -ForegroundColor Green
& sqlcmd -S $Server -U sa -P $SaPassword -C -d $Database -h -1 -W -Q @"
SET NOCOUNT ON;
SELECT 'Products          = ' + CAST((SELECT COUNT(*) FROM product.Products) AS VARCHAR(10));
SELECT 'ProductVariants   = ' + CAST((SELECT COUNT(*) FROM product.ProductVariants) AS VARCHAR(10));
SELECT 'VariantSpecValues = ' + CAST((SELECT COUNT(*) FROM product.ProductVariantSpecificationValues) AS VARCHAR(10));
SELECT 'Customers         = ' + CAST((SELECT COUNT(*) FROM customer.Customers) AS VARCHAR(10));
SELECT 'Permissions       = ' + CAST((SELECT COUNT(*) FROM [identity].Permissions) AS VARCHAR(10));
"@
