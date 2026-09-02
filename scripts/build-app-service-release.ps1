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
    (Join-Path $apiRoot 'deploy\app-service\api\index.php')
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

$stagedCore = Join-Path $appRoot 'core'
Assert-NoReparsePoints -Root $stagedCore
Remove-Item -LiteralPath $stagedCore -Recurse -Force

$apiPublicRoot = Join-Path $releaseRoot 'api'
New-Item -ItemType Directory -Path $apiPublicRoot -Force | Out-Null
Copy-Item -LiteralPath (Join-Path $apiRoot 'deploy\app-service\api\index.php') -Destination (Join-Path $apiPublicRoot 'index.php') -Force
Copy-Item -LiteralPath (Join-Path $apiRoot 'deploy\app-service\default') -Destination (Join-Path $releaseRoot 'default') -Force

if (Test-Path -LiteralPath $zipPath) {
    Remove-Item -LiteralPath $zipPath -Force
}

Compress-Archive -Path (Join-Path $releaseRoot '*') -DestinationPath $zipPath -CompressionLevel Optimal

$zip = Get-Item -LiteralPath $zipPath
Write-Output "Release directory: $releaseRoot"
Write-Output "Release ZIP: $zipPath"
Write-Output ("Release ZIP size: {0:N2} MiB" -f ($zip.Length / 1MB))
Write-Output "Frontend origin: $WebOrigin"
