[CmdletBinding()]
param(
    [ValidatePattern('^https://[^/]+$')]
    [string] $WebOrigin = 'https://tc-web-core-e7b7gdbjgadjd3ff.australiasoutheast-01.azurewebsites.net',

    [switch] $SkipNpmCi
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Invoke-CheckedCommand {
    param(
        [Parameter(Mandatory)]
        [string] $Command,

        [Parameter(Mandatory)]
        [string[]] $Arguments
    )

    & $Command @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "$Command failed with exit code $LASTEXITCODE."
    }
}

function Assert-NoReparsePoints {
    param(
        [Parameter(Mandatory)]
        [string] $Root
    )

    $pending = [System.Collections.Generic.Stack[string]]::new()
    $pending.Push($Root)

    while ($pending.Count -gt 0) {
        $current = $pending.Pop()
        foreach ($child in Get-ChildItem -LiteralPath $current -Force) {
            if (($child.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) {
                throw "Refusing to clean an artifact directory containing a reparse point: $($child.FullName)"
            }

            if ($child.PSIsContainer) {
                $pending.Push($child.FullName)
            }
        }
    }
}

function Set-ProcessEnvironmentValue {
    param(
        [Parameter(Mandatory)]
        [string] $Name,

        [AllowNull()]
        [string] $Value
    )

    [Environment]::SetEnvironmentVariable($Name, $Value, 'Process')
}

$apiRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$workspaceRoot = [IO.Path]::GetFullPath((Join-Path $apiRoot '..'))
$webRoot = [IO.Path]::GetFullPath((Join-Path $workspaceRoot 'tricab-web'))
$artifactsRoot = [IO.Path]::GetFullPath((Join-Path $apiRoot 'artifacts\app-service'))
$releaseRoot = Join-Path $artifactsRoot 'release'
$zipPath = Join-Path $artifactsRoot 'tricab-app-service.zip'

$artifactBoundary = [IO.Path]::GetFullPath((Join-Path $apiRoot 'artifacts')).TrimEnd('\', '/') + [IO.Path]::DirectorySeparatorChar
if (-not $artifactsRoot.StartsWith($artifactBoundary, [StringComparison]::OrdinalIgnoreCase)) {
    throw "Artifact path must stay inside the API repository's artifacts directory: $artifactsRoot"
}

foreach ($requiredPath in @(
    (Join-Path $apiRoot 'composer.json'),
    (Join-Path $apiRoot 'composer.lock'),
    (Join-Path $apiRoot 'core\composer.json'),
    (Join-Path $webRoot 'package.json'),
    (Join-Path $webRoot 'package-lock.json'),
    (Join-Path $apiRoot 'deploy\app-service\default'),
    (Join-Path $apiRoot 'deploy\app-service\startup.sh'),
    (Join-Path $apiRoot 'deploy\app-service\api\index.php'),
    (Join-Path $apiRoot 'core\python\requirements.txt'),
    (Join-Path $apiRoot 'core\python\wsgi.py')
)) {
    if (-not (Test-Path -LiteralPath $requiredPath -PathType Leaf)) {
        throw "Required release input is missing: $requiredPath"
    }
}

if (Test-Path -LiteralPath $artifactsRoot) {
    $artifactItem = Get-Item -LiteralPath $artifactsRoot -Force
    if (-not $artifactItem.PSIsContainer) {
        throw "Artifact path is not a directory: $artifactsRoot"
    }
    if (($artifactItem.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) {
        throw "Artifact root must not be a reparse point: $artifactsRoot"
    }

    Assert-NoReparsePoints -Root $artifactsRoot
    Remove-Item -LiteralPath $artifactsRoot -Recurse -Force
}

New-Item -ItemType Directory -Path $releaseRoot -Force | Out-Null

$previousApiBaseUrl = [Environment]::GetEnvironmentVariable('VITE_API_BASE_URL', 'Process')
$previousRedirectUri = [Environment]::GetEnvironmentVariable('VITE_ENTRA_REDIRECT_URI', 'Process')

try {
    Set-ProcessEnvironmentValue -Name 'VITE_API_BASE_URL' -Value $WebOrigin
    Set-ProcessEnvironmentValue -Name 'VITE_ENTRA_REDIRECT_URI' -Value $WebOrigin

    Push-Location $webRoot
    try {
        if (-not $SkipNpmCi) {
            Invoke-CheckedCommand -Command 'npm' -Arguments @('ci')
        }
        Invoke-CheckedCommand -Command 'npm' -Arguments @('run', 'build')
    }
    finally {
        Pop-Location
    }
}
finally {
    Set-ProcessEnvironmentValue -Name 'VITE_API_BASE_URL' -Value $previousApiBaseUrl
    Set-ProcessEnvironmentValue -Name 'VITE_ENTRA_REDIRECT_URI' -Value $previousRedirectUri
}

$webDist = Join-Path $webRoot 'dist'
if (-not (Test-Path -LiteralPath (Join-Path $webDist 'index.html') -PathType Leaf)) {
    throw "Vite output is missing index.html: $webDist"
}
Copy-Item -Path (Join-Path $webDist '*') -Destination $releaseRoot -Recurse -Force

$appRoot = Join-Path $releaseRoot 'app'
New-Item -ItemType Directory -Path $appRoot -Force | Out-Null

foreach ($directory in @('src', 'resources', 'PROMPTS', 'core')) {
    $source = Join-Path $apiRoot $directory
    if (-not (Test-Path -LiteralPath $source -PathType Container)) {
        throw "Required API directory is missing: $source"
    }
    Copy-Item -LiteralPath $source -Destination (Join-Path $appRoot $directory) -Recurse -Force
}

foreach ($file in @('composer.json', 'composer.lock')) {
    Copy-Item -LiteralPath (Join-Path $apiRoot $file) -Destination (Join-Path $appRoot $file) -Force
}

# Public content images (About / Technical / catalogue static copies) live under
# public/media. nginx serves them at the site-root /media/ path (not under
# /api/v1), and PHP's availability checks read that same copy: from the app at
# <wwwroot>/app they resolve to ../media (Content\PublicMediaDirectory). One
# copy only.
Write-Host '==> Staging public media'
$mediaSrc = Join-Path $apiRoot 'public\media'
if (-not (Test-Path -LiteralPath $mediaSrc -PathType Container)) {
    throw "Required public media directory is missing: $mediaSrc"
}
Copy-Item -LiteralPath $mediaSrc -Destination (Join-Path $releaseRoot 'media') -Recurse -Force
foreach ($requiredMedia in @(
    (Join-Path $releaseRoot 'media\about\melbourne-facility.png'),
    (Join-Path $releaseRoot 'media\technical\lugging\lugging-1.jpg')
)) {
    if (-not (Test-Path -LiteralPath $requiredMedia -PathType Leaf)) {
        throw "Release media missing: $requiredMedia"
    }
}

foreach ($directory in @('var\cache\app', 'var\cache\apple-jwks', 'var\cache\entra-jwks', 'var\cache\google-jwks', 'var\log')) {
    New-Item -ItemType Directory -Path (Join-Path $appRoot $directory) -Force | Out-Null
}

# The core path repository is symlinked in local development so core/src is never
# stale. The release must be self-contained instead: force Composer to mirror it,
# because the staged core directory is deleted immediately below.
$previousMirrorPathRepos = [Environment]::GetEnvironmentVariable('COMPOSER_MIRROR_PATH_REPOS', 'Process')
Set-ProcessEnvironmentValue -Name 'COMPOSER_MIRROR_PATH_REPOS' -Value '1'

Push-Location $appRoot
try {
    Invoke-CheckedCommand -Command 'composer' -Arguments @(
        'install',
        '--no-dev',
        '--prefer-dist',
        '--optimize-autoloader',
        '--classmap-authoritative',
        '--no-interaction'
    )
}
finally {
    Pop-Location
    Set-ProcessEnvironmentValue -Name 'COMPOSER_MIRROR_PATH_REPOS' -Value $previousMirrorPathRepos
}

$vendorCore = Get-Item -LiteralPath (Join-Path $appRoot 'vendor\tricab\tricab-core')
if (($vendorCore.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) {
    throw "Release vendor Core must be mirrored, not linked: $($vendorCore.FullName)"
}

# Core's development surface is not runtime: autoload is src/ only. docs/ alone
# is 22 MB. Same list as build-app-service-release.sh.
foreach ($devOnly in @('docs', 'tests', 'phpunit.xml', 'phpunit.xml.dist', 'phpstan.neon', 'phpstan.neon.dist', 'README.md', '.gitignore', '.github')) {
    $devPath = Join-Path $vendorCore.FullName $devOnly
    if (Test-Path -LiteralPath $devPath) {
        Remove-Item -LiteralPath $devPath -Recurse -Force
    }
}

# Conservative prune of third-party packages: docs/tests/CI directories exactly
# one level inside vendor/<org>/<pkg>, as in the .sh.
Get-ChildItem -LiteralPath (Join-Path $appRoot 'vendor') -Directory |
    ForEach-Object { Get-ChildItem -LiteralPath $_.FullName -Directory } |
    ForEach-Object { Get-ChildItem -LiteralPath $_.FullName -Directory -Force | Where-Object { $_.Name -in @('tests', 'docs', 'doc', '.github') } } |
    ForEach-Object { Remove-Item -LiteralPath $_.FullName -Recurse -Force }

# The product finder's Python. `app/core` is deleted immediately below because
# Composer has already mirrored it into vendor/tricab/tricab-core, so Python
# left under core/ would go with it -- it survives only in vendor, at a path
# nothing should depend on. Stage it to `app/python` instead: a stable location
# for the gunicorn --chdir, and outside the vendor tree.
$stagedCore = Join-Path $appRoot 'core'
$sourcePython = Join-Path $stagedCore 'python'
if (-not (Test-Path -LiteralPath $sourcePython -PathType Container)) {
    throw "Required API directory is missing: $sourcePython"
}

# A virtualenv under core/python is hundreds of megabytes and Composer's path
# copy is wholesale, so it would already be in vendor by now. Fail rather than
# ship it.
foreach ($unwanted in @('.venv', 'venv', '.pytest_cache')) {
    $strays = @(Get-ChildItem -LiteralPath $sourcePython -Recurse -Force -Directory -Filter $unwanted -ErrorAction SilentlyContinue)
    if ($strays.Count -gt 0) {
        throw "core/python must not contain a $unwanted directory: $($strays[0].FullName)"
    }
}

$appPython = Join-Path $appRoot 'python'
Copy-Item -LiteralPath $sourcePython -Destination $appPython -Recurse -Force
foreach ($prune in @('__pycache__', '.pytest_cache')) {
    Get-ChildItem -LiteralPath $appPython -Recurse -Force -Directory -Filter $prune -ErrorAction SilentlyContinue |
        ForEach-Object { Remove-Item -LiteralPath $_.FullName -Recurse -Force }
}
# `tests/` and `tools/` are development surface, not runtime.
foreach ($devOnly in @('tests', 'tools')) {
    $devPath = Join-Path $appPython $devOnly
    if (Test-Path -LiteralPath $devPath) {
        Remove-Item -LiteralPath $devPath -Recurse -Force
    }
}

# Composer mirrored core/ wholesale, Python included. Drop that second copy:
# two copies of the selection rules on one disk is the exact thing
# tricab_cable_rules exists to prevent, even when one of them is unreachable.
$vendorPython = Join-Path $appRoot 'vendor\tricab\tricab-core\python'
if (Test-Path -LiteralPath $vendorPython) {
    Remove-Item -LiteralPath $vendorPython -Recurse -Force
}

Assert-NoReparsePoints -Root $stagedCore
Remove-Item -LiteralPath $stagedCore -Recurse -Force

$apiPublicRoot = Join-Path $releaseRoot 'api'
New-Item -ItemType Directory -Path $apiPublicRoot -Force | Out-Null
Copy-Item -LiteralPath (Join-Path $apiRoot 'deploy\app-service\api\index.php') -Destination (Join-Path $apiPublicRoot 'index.php') -Force
Copy-Item -LiteralPath (Join-Path $apiRoot 'deploy\app-service\default') -Destination (Join-Path $releaseRoot 'default') -Force
Copy-Item -LiteralPath (Join-Path $apiRoot 'deploy\app-service\startup.sh') -Destination (Join-Path $releaseRoot 'startup.sh') -Force

# The RFQ reader worker and the continuous WebJob that runs it on the existing slot.
$readerJobRoot = Join-Path $releaseRoot 'App_Data\jobs\continuous\rfq-reader'
New-Item -ItemType Directory -Path (Join-Path $appRoot 'bin') -Force | Out-Null
New-Item -ItemType Directory -Path $readerJobRoot -Force | Out-Null
Copy-Item -LiteralPath (Join-Path $apiRoot 'bin\run-rfq-reader-jobs.php') -Destination (Join-Path $appRoot 'bin\run-rfq-reader-jobs.php') -Force
Copy-Item -LiteralPath (Join-Path $apiRoot 'deploy\app-service\webjobs\rfq-reader\run.sh') -Destination (Join-Path $readerJobRoot 'run.sh') -Force

# The RFQ mail reader (M3/M4) and its continuous WebJob, the same way.
$mailJobRoot = Join-Path $releaseRoot 'App_Data\jobs\continuous\rfq-mail'
New-Item -ItemType Directory -Path $mailJobRoot -Force | Out-Null
Copy-Item -LiteralPath (Join-Path $apiRoot 'bin\run-rfq-mail-reader.php') -Destination (Join-Path $appRoot 'bin\run-rfq-mail-reader.php') -Force
Copy-Item -LiteralPath (Join-Path $apiRoot 'deploy\app-service\webjobs\rfq-mail\run.sh') -Destination (Join-Path $mailJobRoot 'run.sh') -Force

# The NPR workflow email retry sweep (N2.4): idle passes never touch the database.
$nprMailJobRoot = Join-Path $releaseRoot 'App_Data\jobs\continuous\npr-mail'
New-Item -ItemType Directory -Path $nprMailJobRoot -Force | Out-Null
Copy-Item -LiteralPath (Join-Path $apiRoot 'bin\run-npr-mail-sweep.php') -Destination (Join-Path $appRoot 'bin\run-npr-mail-sweep.php') -Force
Copy-Item -LiteralPath (Join-Path $apiRoot 'deploy\app-service\webjobs\npr-mail\run.sh') -Destination (Join-Path $nprMailJobRoot 'run.sh') -Force

# The finance job runner and its continuous WebJob (F1.8), the same way. Inert while
# FINANCE_MODULE_ENABLED is not true.
$financeJobRoot = Join-Path $releaseRoot 'App_Data\jobs\continuous\finance-jobs'
New-Item -ItemType Directory -Path $financeJobRoot -Force | Out-Null
Copy-Item -LiteralPath (Join-Path $apiRoot 'bin\run-finance-jobs.php') -Destination (Join-Path $appRoot 'bin\run-finance-jobs.php') -Force
Copy-Item -LiteralPath (Join-Path $apiRoot 'deploy\app-service\webjobs\finance-jobs\run.sh') -Destination (Join-Path $financeJobRoot 'run.sh') -Force

# The CableFit research worker (C3) and its continuous WebJob, the same way.
$researchJobRoot = Join-Path $releaseRoot 'App_Data\jobs\continuous\cablefit-research'
New-Item -ItemType Directory -Path $researchJobRoot -Force | Out-Null
Copy-Item -LiteralPath (Join-Path $apiRoot 'bin\run-cablefit-research.php') -Destination (Join-Path $appRoot 'bin\run-cablefit-research.php') -Force
Copy-Item -LiteralPath (Join-Path $apiRoot 'deploy\app-service\webjobs\cablefit-research\run.sh') -Destination (Join-Path $researchJobRoot 'run.sh') -Force

# The tc-web RFQ refresh (RFQ plan 7.2-7.4) and its continuous WebJob, the same way.
$legacySyncJobRoot = Join-Path $releaseRoot 'App_Data\jobs\continuous\rfq-legacy-sync'
New-Item -ItemType Directory -Path $legacySyncJobRoot -Force | Out-Null
Copy-Item -LiteralPath (Join-Path $apiRoot 'bin\sync-rfq-legacy-store.php') -Destination (Join-Path $appRoot 'bin\sync-rfq-legacy-store.php') -Force
Copy-Item -LiteralPath (Join-Path $apiRoot 'deploy\app-service\webjobs\rfq-legacy-sync\run.sh') -Destination (Join-Path $legacySyncJobRoot 'run.sh') -Force

# Continuous WebJobs run on every instance by default; each job ships a settings.job with is_singleton.
foreach ($jobDir in Get-ChildItem -LiteralPath (Join-Path $releaseRoot 'App_Data\jobs\continuous') -Directory) {
    $jobSettings = Join-Path $apiRoot ('deploy\app-service\webjobs\' + $jobDir.Name + '\settings.job')
    if (-not (Test-Path -LiteralPath $jobSettings -PathType Leaf)) {
        throw "WebJob is missing its settings.job: $jobSettings"
    }
    Copy-Item -LiteralPath $jobSettings -Destination (Join-Path $jobDir.FullName 'settings.job') -Force
}

if (Test-Path -LiteralPath $zipPath) {
    Remove-Item -LiteralPath $zipPath -Force
}

# Not Compress-Archive: Windows PowerShell 5.1 stores entries as app\bin\x.php, which the Linux
# App Service unpacks as literal backslash names and the deployment fails. Windows' bsdtar writes
# forward slashes. Called by full path because Git for Windows puts a GNU tar without zip on PATH.
$tarExe = Join-Path $env:SystemRoot 'System32\tar.exe'
& $tarExe -a -c -f $zipPath -C $releaseRoot .
if ($LASTEXITCODE -ne 0) {
    throw "tar failed with exit code $LASTEXITCODE."
}

$zip = Get-Item -LiteralPath $zipPath
Write-Output "Release directory: $releaseRoot"
Write-Output "Release ZIP: $zipPath"
Write-Output ("Release ZIP size: {0:N2} MiB" -f ($zip.Length / 1MB))
Write-Output "Frontend origin: $WebOrigin"
