param([ValidateSet('setup', 'start', 'stop', 'backup', 'restore', 'update', 'remote', 'remote-off')][string]$Action)
$ErrorActionPreference = 'Stop'
Set-Location $PSScriptRoot

function Invoke-Docker {
    & docker.exe @args
    if ($LASTEXITCODE -ne 0) { throw "Docker operation failed ($LASTEXITCODE). No further steps were run." }
}
function Compose {
    Invoke-Docker compose --env-file release.env --env-file .env -f compose.yaml @args
}
function Secret {
    $bytes = New-Object byte[] 32
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
    return [Convert]::ToBase64String($bytes)
}
function Read-Setting($name, $fallback = '') {
    $line = Get-Content .env | Where-Object { $_ -match ('^' + [regex]::Escape($name) + '=') } | Select-Object -Last 1
    if ($line) { return ($line -split '=', 2)[1].Trim() }
    return $fallback
}
function Write-Setting($name, $value) {
    $lines = @(Get-Content .env | Where-Object { $_ -notmatch ('^' + [regex]::Escape($name) + '=') })
    @($lines) + "$name=$value" | Set-Content .env -Encoding ASCII
}
function Show-AccessLinks {
    $port = Read-Setting 'AQUAFLOW_HTTP_PORT' '8080'
    Write-Host "`nADMIN PC: http://localhost:$port"
    try {
        $interfaces = @(Get-NetIPConfiguration -ErrorAction Stop | Where-Object { $_.IPv4DefaultGateway -and $_.NetAdapter.Status -eq 'Up' })
        foreach ($interface in $interfaces) {
            foreach ($ip in $interface.IPv4Address.IPAddress) {
                Write-Host "CASHIER LAN / WI-FI ($($interface.InterfaceAlias)): http://${ip}:$port"
            }
        }
        if (!$interfaces.Count) { Write-Host 'LAN address unavailable. Connect the host to the store router and run Start again.' }
    } catch { Write-Host 'LAN address unavailable. Use the host IPv4 address shown by ipconfig.' }
    Write-Host 'Cashier phones and PCs must be on the same router; allow this port on the Windows private-network firewall.'
    if ((Read-Setting 'NGROK_ENABLED' '0') -ne '1') {
        # Stop a previously enabled tunnel even when the owner disabled it in .env.
        Compose --profile remote stop ngrok
        Write-Host 'INTERNET LINK: Off. Run Configure Remote Access.cmd only if needed.'
        return
    }
    if (!(Read-Setting 'NGROK_AUTHTOKEN')) {
        Compose --profile remote stop ngrok
        Write-Host 'INTERNET LINK: Missing token. Run Configure Remote Access.cmd. LAN remains available.'
        return
    }
    try {
        Compose --profile remote up -d --no-deps ngrok
        $apiPort = Read-Setting 'NGROK_API_PORT' '4041'
        for ($attempt = 0; $attempt -lt 10; $attempt++) {
            try {
                $status = Invoke-RestMethod "http://127.0.0.1:$apiPort/api/tunnels" -TimeoutSec 2
                $tunnel = $status.tunnels | Where-Object { $_.proto -eq 'https' -and $_.config.addr -in @('http://web:81', 'web:81') -and $_.public_url -match '^https://' } | Select-Object -First 1
                if ($tunnel) {
                    Write-Host "INTERNET CASHIER LINK: $($tunnel.public_url)" -ForegroundColor Green
                    Write-Host 'The internet link exposes the login pages publicly. Keep store credentials private.'
                    return
                }
            } catch { }
            Start-Sleep -Seconds 1
        }
        Write-Host 'INTERNET LINK: Not ready. Check internet, your ngrok token/account limits, then run Start again. LAN remains available.'
    } catch { Write-Host 'INTERNET LINK: Could not start. Check Docker and ngrok configuration. LAN remains available.' }
}
function Import-Images {
    if (!(Test-Path images.tar)) { throw 'images.tar is missing. Extract the complete release package.' }
    Invoke-Docker load --input images.tar
}
function Backup-Store {
    New-Item backups -ItemType Directory -Force | Out-Null
    $name = 'backup-' + (Get-Date -Format 'yyyyMMdd-HHmmss-fff')
    $folder = Join-Path $PSScriptRoot "backups/$name"
    New-Item $folder -ItemType Directory | Out-Null
    # Dump inside Linux; PowerShell 5 text redirection can corrupt SQL encoding.
    Compose exec -T db sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqldump -uroot --single-transaction --routines --triggers --events --no-tablespaces --set-gtid-purged=OFF aquaflow_db > /tmp/aquaflow-backup.sql'
    Compose cp db:/tmp/aquaflow-backup.sql "$folder/database.sql"
    Copy-Item .env "$folder/store.env"
    if (Test-Path installed.env) { Copy-Item installed.env "$folder/release.env" }
    else { Copy-Item release.env "$folder/release.env" }
    if ((Get-Item "$folder/database.sql").Length -eq 0) { throw 'Empty backup. Do not use it.' }
    Write-Host "Backup saved to $folder. Keep it private: it includes credentials and store data."
    return $folder
}
function Start-Store {
    Compose up -d --wait --wait-timeout 300 db app worker analytics web
    # Nginx resolves its upstream on startup, including after app replacement.
    Compose restart web
    Show-AccessLinks
    $port = Read-Setting 'AQUAFLOW_HTTP_PORT' '8080'
    Start-Process "http://localhost:$port"
}

$mutex = New-Object Threading.Mutex($false, 'Local\AquaFlowStoreLauncher')
$locked = $false
try {
    $locked = $mutex.WaitOne(0)
    if (!$locked) { throw 'Another AquaFlow launcher is running. Finish it first.' }
    if (!(Get-Command docker -ErrorAction SilentlyContinue)) { throw 'Install Docker Desktop with WSL 2, then reopen this launcher.' }
    Invoke-Docker info --format '{{.OSType}}'
    if (!(Test-Path release.env)) { throw 'This is the source template. Build a release package first.' }
    if ($Action -eq 'setup' -and !(Test-Path .env)) {
        @(
            'APP_KEY=base64:' + (Secret)
            'MYSQL_ROOT_PASSWORD=' + (Secret)
            'DB_PASSWORD=' + (Secret)
            'APP_URL=http://localhost:8080'
            'AQUAFLOW_HTTP_PORT=8080'
            'NGROK_ENABLED=0'
            'NGROK_AUTHTOKEN='
            'NGROK_API_PORT=4041'
        ) | Set-Content .env -Encoding ASCII
    }
    if (!(Test-Path .env)) { throw 'Run Setup.cmd first. For recovery, follow Getting Started.md before continuing.' }
    if ($Action -eq 'start' -and !(Test-Path installed.env)) { throw 'Setup has not finished. Run Setup.cmd first.' }
    if ($Action -in @('setup', 'start') -and (Test-Path installed.env)) {
        if ((Get-Content installed.env -Raw).Trim() -ne (Get-Content release.env -Raw).Trim()) {
            throw 'A different release is installed. Run Update.cmd to back up and migrate before starting.'
        }
    }
    switch ($Action) {
        'setup' {
            Import-Images
            Compose up -d --wait --wait-timeout 300 db
            Compose run --rm --no-deps app php artisan migrate --force
            Compose run --rm --no-deps app php artisan aquaflow:install-store
            Copy-Item release.env installed.env -Force
            Start-Store
        }
        'start' { Start-Store }
        'stop' { Compose --profile remote stop }
        'remote' {
            Write-Host 'This enables a public HTTPS tunnel to this store, including both login pages.'
            Write-Host 'Get this store PC''s authtoken from https://dashboard.ngrok.com/get-started/your-authtoken'
            $secureToken = Read-Host 'Paste the ngrok authtoken (hidden)' -AsSecureString
            $pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secureToken)
            try { $token = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer) }
            finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer) }
            if ($token -notmatch '^[A-Za-z0-9_-]{20,}$') { throw 'Invalid token format. Configuration was not changed.' }
            Write-Setting 'NGROK_AUTHTOKEN' $token
            $token = $null
            Write-Setting 'NGROK_ENABLED' '1'
            Write-Host 'Remote access configured. Run Start AquaFlow.cmd to display the link.'
        }
        'remote-off' {
            Write-Setting 'NGROK_ENABLED' '0'
            Compose --profile remote stop ngrok
            Write-Host 'Internet access disabled. Local-network access remains available.'
        }
        'backup' {
            Compose up -d --wait --wait-timeout 300 db
            $null = Backup-Store
        }
        'update' {
            Compose up -d --wait --wait-timeout 300 db
            Compose --profile remote stop ngrok web worker app analytics
            $null = Backup-Store
            Import-Images
            Compose run --rm --no-deps app php artisan migrate --force
            Copy-Item release.env installed.env -Force
            Start-Store
        }
        'restore' {
            $folder = (Read-Host 'Full path to the backup folder').Trim('"')
            foreach ($file in @('database.sql', 'store.env', 'release.env')) {
                if (!(Test-Path -LiteralPath (Join-Path $folder $file))) { throw "Backup is missing $file" }
            }
            if ((Get-Content (Join-Path $folder release.env) -Raw).Trim() -ne (Get-Content release.env -Raw).Trim()) {
                throw 'Restore using the same AquaFlow release as the backup, then upgrade.'
            }
            if ((Read-Host 'This replaces current store data. Type RESTORE to continue') -cne 'RESTORE') { throw 'Restore cancelled.' }
            $key = @(Get-Content (Join-Path $folder store.env) | Where-Object { $_ -match '^APP_KEY=base64:[A-Za-z0-9+/]{43}=$' })
            if ($key.Count -ne 1) { throw 'Backup must contain exactly one valid APP_KEY.' }
            Compose up -d --wait --wait-timeout 300 db
            Compose --profile remote stop ngrok web worker app analytics
            $null = Backup-Store
            Compose cp (Join-Path $folder database.sql) db:/tmp/aquaflow-restore.sql
            Compose exec -T db sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot aquaflow_db < /tmp/aquaflow-restore.sql'
            # Preserve this database's passwords but recover the original encryption key.
            $lines = Get-Content .env | Where-Object { $_ -notmatch '^APP_KEY=' }
            @($lines) + $key | Set-Content .env -Encoding ASCII
            Copy-Item release.env installed.env -Force
            Start-Store
        }
    }
} catch {
    Write-Host $_.Exception.Message -ForegroundColor Red
    exit 1
} finally {
    if ($locked) { $mutex.ReleaseMutex() }
    $mutex.Dispose()
}
