$ErrorActionPreference = 'Stop'
$root = Split-Path $PSScriptRoot -Parent
$workspace = Join-Path ([IO.Path]::GetTempPath()) ('aquaflow-launcher-test-' + [Guid]::NewGuid())
New-Item $workspace -ItemType Directory | Out-Null
Copy-Item "$root/distribution/AquaFlow.ps1" $workspace
Set-Content "$workspace/release.env" 'AQUAFLOW_VERSION=test'
Set-Content "$workspace/images.tar" 'mock image archive'
$global:calls = [Collections.Generic.List[string]]::new()
function global:docker.exe {
    $global:calls.Add(($args -join ' '))
    $global:LASTEXITCODE = 0
    if ($args -contains 'cp') {
        $target = $args[-1]
        if ($target -like '*/database.sql') { Set-Content $target '-- mock SQL dump' }
    }
}
function global:Start-Process { param($FilePath) }
function global:Read-Host {
    param($Prompt, [switch]$AsSecureString)
    $answer = $global:answers.Dequeue()
    if ($AsSecureString) { return ConvertTo-SecureString $answer -AsPlainText -Force }
    return $answer
}
function global:Get-NetIPConfiguration {
    [pscustomobject]@{ IPv4DefaultGateway = '192.168.1.1'; NetAdapter = @{ Status = 'Up' }; InterfaceAlias = 'Wi-Fi'; IPv4Address = @{ IPAddress = '192.168.1.25' } }
}
function global:Start-Sleep { param($Seconds) }
$global:tunnelAvailable = $true
function global:Invoke-RestMethod {
    param($Uri, $TimeoutSec)
    if (!$global:tunnelAvailable) { throw 'Mock tunnel unavailable' }
    return @{ tunnels = @(@{ proto = 'https'; config = @{ addr = 'http://web:81' }; public_url = 'https://store.example.ngrok.app' }) }
}
function Assert($condition, $message) { if (!$condition) { throw $message } }
try {
    $output = (& "$workspace/AquaFlow.ps1" setup 6>&1) -join "`n"
    Assert ($output -match 'http://192.168.1.25:8080') 'LAN address was not displayed'
    $original = Get-Content "$workspace/.env" -Raw
    Assert ($original -match 'APP_KEY=base64:[A-Za-z0-9+/]{43}=') 'Missing encryption key'
    Assert (($original -split "`n" | Where-Object { $_ -match '^.*PASSWORD=' }).Count -eq 2) 'Missing database secrets'
    Assert (($global:calls -join "`n") -notmatch 'seed|generate-history|down|volume rm') 'Destructive or demo command found'
    & "$workspace/AquaFlow.ps1" setup
    Assert ((Get-Content "$workspace/.env" -Raw) -eq $original) 'Setup replaced existing secrets'
    Set-Content "$workspace/release.env" 'AQUAFLOW_VERSION=new'
    $global:calls.Clear()
    & "$workspace/AquaFlow.ps1" update
    $callText = $global:calls -join "`n"
    Assert ($callText.IndexOf('mysqldump') -lt $callText.IndexOf('load --input')) 'Update loaded images before backup'
    Assert ($callText.IndexOf('stop ngrok web worker app analytics') -lt $callText.IndexOf('mysqldump')) 'Update did not stop writers before backup'
    $backup = Get-ChildItem "$workspace/backups" -Directory | Select-Object -First 1
    Assert ((Get-Content "$($backup.FullName)/release.env") -eq 'AQUAFLOW_VERSION=test') 'Backup has incorrect previous release version'
    Assert ((Get-Content "$workspace/installed.env") -eq 'AQUAFLOW_VERSION=new') 'Installed version not recorded'
    Set-Content "$workspace/release.env" 'AQUAFLOW_VERSION=test'
    $global:answers = [Collections.Generic.Queue[string]]::new()
    $global:answers.Enqueue($backup.FullName)
    $global:answers.Enqueue('RESTORE')
    $global:calls.Clear()
    & "$workspace/AquaFlow.ps1" restore
    $callText = $global:calls -join "`n"
    Assert ($callText.IndexOf('mysqldump') -lt $callText.IndexOf('mysql -uroot aquaflow_db <')) 'Restore overwrote data before backup'
    $restored = (Get-Content "$workspace/.env" | Sort-Object) -join "`n"
    $expected = ($original.Trim() -split "`r?`n" | Sort-Object) -join "`n"
    Assert ($restored -eq $expected) 'Restore changed database secrets or lost original encryption key'
    $global:answers.Enqueue('MockNgrokToken1234567890')
    & "$workspace/AquaFlow.ps1" remote
    $output = (& "$workspace/AquaFlow.ps1" start 6>&1) -join "`n"
    Assert ($output -match 'INTERNET CASHIER LINK: https://store.example.ngrok.app') 'Live HTTPS link missing'
    Assert ($output -notmatch 'MockNgrokToken') 'Token was printed'
    $global:tunnelAvailable = $false
    $output = (& "$workspace/AquaFlow.ps1" start 6>&1) -join "`n"
    Assert ($output -match 'Not ready' -and $output -match 'http://192.168.1.25:8080') 'Tunnel failure prevented LAN access'
    & "$workspace/AquaFlow.ps1" remote-off
    Assert ((Get-Content "$workspace/.env" -Raw) -match 'NGROK_ENABLED=0') 'Remote disable failed'
    & "$workspace/AquaFlow.ps1" stop
    Assert ($global:calls[-1] -match '--profile remote stop$') 'Stop left tunnel running'
    Write-Host 'Launcher checks passed: installation, backup/restore, LAN links, ngrok links/failure, token privacy and tunnel shutdown.'
} finally {
    Set-Location $root
    Remove-Item Function:\docker.exe, Function:\Start-Process, Function:\Read-Host, Function:\Get-NetIPConfiguration, Function:\Start-Sleep, Function:\Invoke-RestMethod -ErrorAction SilentlyContinue
    # Keep fixtures for inspecting failures; only temporary mock data is written here.
    Write-Host "Test fixtures: $workspace"
}
