# Builds dist\store-mcp-for-woocommerce.zip, ready for Plugins -> Add New -> Upload Plugin.
# Leaves out tests, Composer files and anything else only needed for development.
#
# Usage (from the project folder):  powershell -ExecutionPolicy Bypass -File dev\build-zip.ps1

$ErrorActionPreference = 'Stop'

$root    = Split-Path -Parent $PSScriptRoot
$plugin  = Join-Path $root 'store-mcp-for-woocommerce'
$dist    = Join-Path $root 'dist'
$staging = Join-Path ([IO.Path]::GetTempPath()) ('wcmcp-build-' + [guid]::NewGuid())
$target  = Join-Path $staging 'store-mcp-for-woocommerce'
$zip     = Join-Path $dist 'store-mcp-for-woocommerce.zip'

# Development-only paths, relative to the plugin folder.
$exclude = @('vendor', 'tests', 'composer.json', 'composer.lock', 'phpunit.xml.dist', '.phpunit.result.cache', 'node_modules', '.git', '.gitignore', '.distignore')

New-Item -ItemType Directory -Force $target | Out-Null
Get-ChildItem -LiteralPath $plugin -Force | Where-Object { $exclude -notcontains $_.Name } | ForEach-Object {
    Copy-Item -LiteralPath $_.FullName -Destination $target -Recurse -Force
}

New-Item -ItemType Directory -Force $dist | Out-Null
if (Test-Path -LiteralPath $zip) { Remove-Item -LiteralPath $zip -Force }

# Compress-Archive in Windows PowerShell 5.1 writes "\" in entry names, which Linux servers
# unpack as literal backslashes. Build the archive with "/" separators instead.
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
$archive = [IO.Compression.ZipFile]::Open($zip, [IO.Compression.ZipArchiveMode]::Create)
try {
    Get-ChildItem -LiteralPath $target -Recurse -File | ForEach-Object {
        $relative = $_.FullName.Substring($staging.Length + 1).Replace('\', '/')
        [IO.Compression.ZipFileExtensions]::CreateEntryFromFile($archive, $_.FullName, $relative, [IO.Compression.CompressionLevel]::Optimal) | Out-Null
    }
} finally {
    $archive.Dispose()
}

$count = (Get-ChildItem -LiteralPath $target -Recurse -File).Count
Remove-Item -LiteralPath $staging -Recurse -Force

Write-Output "Built $zip ($count files)."
