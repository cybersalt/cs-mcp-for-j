# Cybersalt MCP for Joomla — build script
# Produces pkg_csmcpforj_v{version}_{yyyymmdd}_{hhmm}.zip in the project root.
#
# Requires 7-Zip at the default install path. PowerShell's built-in
# Compress-Archive does NOT create directory entries, which Joomla refuses.
# See Joomla-Brain/PACKAGE-BUILD-NOTES.md.

$ErrorActionPreference = 'Stop'

$root      = $PSScriptRoot
$sevenZip  = 'C:\Program Files\7-Zip\7z.exe'
$pkgXml    = Join-Path $root 'pkg_csmcpforj.xml'
$staging   = Join-Path $root '.build-staging'

if (-not (Test-Path $sevenZip)) {
    throw "7-Zip not found at $sevenZip. Install 7-Zip or edit build.ps1."
}

# Pull version from the package manifest
[xml]$manifest = Get-Content $pkgXml
$version = $manifest.extension.version
if ([string]::IsNullOrWhiteSpace($version)) {
    throw 'Could not read <version> from pkg_csmcpforj.xml.'
}

$timestamp = Get-Date -Format 'yyyyMMdd_HHmm'
$pkgZip    = Join-Path $root "pkg_csmcpforj_v${version}_${timestamp}.zip"

Write-Host "Building cs-mcp-for-j v$version ($timestamp)" -ForegroundColor Cyan

# Clean staging
if (Test-Path $staging) { Remove-Item -Recurse -Force $staging }
New-Item -ItemType Directory -Force -Path $staging | Out-Null
New-Item -ItemType Directory -Force -Path (Join-Path $staging 'packages') | Out-Null
New-Item -ItemType Directory -Force -Path (Join-Path $staging 'language') | Out-Null

# Subpackage list: dev folder name -> output zip name (matches pkg manifest <files>)
$subpackages = @{
    'com_csmcpforj'              = 'com_csmcpforj.zip'
    'plg_system_csmcpforj'       = 'plg_system_csmcpforj.zip'
    'plg_webservices_csmcpforj'  = 'plg_webservices_csmcpforj.zip'
    'plg_system_csmcpforj4seo'   = 'plg_system_csmcpforj4seo.zip'
}

foreach ($srcName in $subpackages.Keys) {
    $srcPath = Join-Path $root "packages\$srcName"
    $outZip  = Join-Path $staging "packages\$($subpackages[$srcName])"

    if (-not (Test-Path $srcPath)) {
        throw "Subpackage source missing: $srcPath"
    }

    Write-Host "  zipping $srcName" -ForegroundColor Gray
    Push-Location $srcPath
    try {
        & $sevenZip a -tzip -mx=9 -bso0 -bsp0 $outZip * | Out-Null
    } finally {
        Pop-Location
    }
}

# Copy package-level files into staging
Copy-Item $pkgXml                              -Destination $staging -Force
Copy-Item (Join-Path $root 'script.php')       -Destination $staging -Force
Copy-Item (Join-Path $root 'language\*')       -Destination (Join-Path $staging 'language') -Recurse -Force

if (Test-Path (Join-Path $root 'LICENSE.txt')) {
    Copy-Item (Join-Path $root 'LICENSE.txt') -Destination $staging -Force
}

# Final package zip
if (Test-Path $pkgZip) { Remove-Item $pkgZip -Force }

Push-Location $staging
try {
    & $sevenZip a -tzip -mx=9 -bso0 -bsp0 $pkgZip * | Out-Null
} finally {
    Pop-Location
}

Remove-Item -Recurse -Force $staging

# Sanity-check directory entries (look for D.... markers)
Write-Host ""
Write-Host "Built: $(Split-Path -Leaf $pkgZip)" -ForegroundColor Green
& $sevenZip l $pkgZip | Select-Object -First 25
