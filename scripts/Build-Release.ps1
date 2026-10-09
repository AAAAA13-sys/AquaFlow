param([Parameter(Mandatory = $true)][ValidatePattern('^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,63}$')][string]$Version)
$ErrorActionPreference = 'Stop'
Set-Location (Split-Path $PSScriptRoot -Parent)
function Invoke-Docker {
    & docker.exe @args
    if ($LASTEXITCODE -ne 0) { throw "Docker build/export failed ($LASTEXITCODE)." }
}
$destination = Join-Path (Get-Location) "dist/AquaFlow-$Version"
if (Test-Path $destination) { throw "Release already exists: $destination. Choose a new version." }
New-Item $destination -ItemType Directory -Force | Out-Null
# Explicit allowlist: never distribute a developer's .env, backups or database.
Get-ChildItem distribution -File | Where-Object { $_.Extension -in '.cmd', '.ps1', '.yaml', '.md' } | Copy-Item -Destination $destination
"AQUAFLOW_VERSION=$Version" | Set-Content "$destination/release.env" -Encoding ASCII
Invoke-Docker build --platform linux/amd64 --target app -f docker/app/Dockerfile -t "aquaflow/app:$Version" .
Invoke-Docker build --platform linux/amd64 -f docker/web/Dockerfile -t "aquaflow/web:$Version" .
Invoke-Docker build --platform linux/amd64 -t "aquaflow/analytics:$Version" ./analytics
Invoke-Docker pull --platform linux/amd64 mysql:8.0
Invoke-Docker pull --platform linux/amd64 ngrok/ngrok:latest
Invoke-Docker save --output "$destination/images.tar" "aquaflow/app:$Version" "aquaflow/web:$Version" "aquaflow/analytics:$Version" mysql:8.0 ngrok/ngrok:latest
# ZipFile supports large image archives, unlike Compress-Archive's size limit.
Add-Type -AssemblyName System.IO.Compression.FileSystem
[IO.Compression.ZipFile]::CreateFromDirectory($destination, "$destination.zip")
$hash = Get-FileHash "$destination.zip" -Algorithm SHA256
$hash.Hash | Set-Content "$destination.zip.sha256" -Encoding ASCII
$hash | Format-List
Write-Host "Release ready: $destination.zip"
