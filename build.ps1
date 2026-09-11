# Cybersalt MCP for Joomla - build script
#
# Default (test build): produces pkg_csmcpforj_v{version}_{yyyymmdd}_{hhmm}.zip
#   so every iteration is uniquely named and the dated zips stack up locally
#   for comparison.
#
# -Release: produces pkg_csmcpforj_v{version}.zip - no date in the filename.
#   This is the artifact that gets attached to the GitHub release and uploaded
#   to cs-release-manager on cybersalt.com (which keys downloads off a stable
#   filename, not a dated one).
#
# Requires 7-Zip at the default install path. PowerShell's built-in
# Compress-Archive does NOT create directory entries, which Joomla refuses.
# See Joomla-Brain/PACKAGE-BUILD-NOTES.md.

param(
    [switch]$Release
)

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

# ---------------------------------------------------------------------------
# Refresh the bundled fallback catalog from the live cs-release-manager
# catalog so it can never drift out of date.
#
# Background (Dragan, GitHub #20): the committed fallback pinned the 4SEO/RST
# add-ons at v1.8.0 in their download_url, but the release server only ever
# had v1.10.2 — so any catalog install that fell back to the bundled file
# asked for a version that did not exist and 404'd ("Requested version not
# found"). Dropping the &version= param pulls the latest and works.
#
# So on every build we pull the live catalog, strip the &version= pin off
# every download_url (fallback always installs latest, drift-proof), and
# relabel source as bundled-fallback. If the fetch fails or looks wrong we
# KEEP the committed file and warn — the build never breaks over this.
# ---------------------------------------------------------------------------
$fallbackPath = Join-Path $root 'packages\com_csmcpforj\admin\catalog.fallback.json'
$catalogUrl   = 'https://www.cybersalt.com/index.php?option=com_csreleasemanager&task=api.catalog&format=json&catalog=cs-mcp-for-j'
try {
    [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
    $resp = Invoke-WebRequest -Uri $catalogUrl -UseBasicParsing -TimeoutSec 15 -ErrorAction Stop
    $body = [string]$resp.Content
    $body = $body -replace '&version=[^&"]*', ''
    $body = $body -replace '"source":\s*"cs-release-manager"', '"source": "bundled-fallback"'
    $parsed = $body | ConvertFrom-Json -ErrorAction Stop
    if ($parsed.addons -and $parsed.addons.Count -gt 0) {
        [System.IO.File]::WriteAllText($fallbackPath, $body, (New-Object System.Text.UTF8Encoding($false)))
        Write-Host "  refreshed fallback catalog from live ($($parsed.addons.Count) add-ons)" -ForegroundColor Gray
    } else {
        Write-Warning "Live catalog returned no add-ons; keeping committed catalog.fallback.json."
    }
} catch {
    Write-Warning "Could not refresh fallback catalog from live ($($_.Exception.Message)); keeping committed catalog.fallback.json."
}

if ($Release) {
    $pkgZip = Join-Path $root "pkg_csmcpforj_v${version}.zip"
    Write-Host "Building cs-mcp-for-j v$version (RELEASE - stable filename)" -ForegroundColor Cyan
} else {
    $timestamp = Get-Date -Format 'yyyyMMdd_HHmm'
    $pkgZip    = Join-Path $root "pkg_csmcpforj_v${version}_${timestamp}.zip"
    Write-Host "Building cs-mcp-for-j v$version ($timestamp)" -ForegroundColor Cyan
}

# Clean staging
if (Test-Path $staging) { Remove-Item -Recurse -Force $staging }
New-Item -ItemType Directory -Force -Path $staging | Out-Null
New-Item -ItemType Directory -Force -Path (Join-Path $staging 'packages') | Out-Null
New-Item -ItemType Directory -Force -Path (Join-Path $staging 'language') | Out-Null

# Subpackage list: dev folder name -> output zip name (matches pkg manifest <files>)
# As of the v2.1 source split (2026-06-18), this repo only contains the three
# CORE extensions. The add-on plugins moved to:
#   e:/github/cs-mcp-for-j-addons-free  (public — Akeeba Backup Core, Cybersalt Release Manager)
#   e:/github/cs-mcp-for-j-addons-pro   (private — 4SEO, RSTicketsPro)
# Each of those repos has its own build.ps1 that emits standalone add-on zips.
$subpackages = @{
    'com_csmcpforj'              = 'com_csmcpforj.zip'
    'plg_system_csmcpforj'       = 'plg_system_csmcpforj.zip'
    'plg_webservices_csmcpforj'  = 'plg_webservices_csmcpforj.zip'
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
