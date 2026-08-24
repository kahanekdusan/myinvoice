[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string]$ImageRef,

    [string]$StackDir = 'C:\docker\fakturace\stacks\production'
)

$ErrorActionPreference = 'Stop'

if ($ImageRef -notmatch '^ghcr\.io/kahanekdusan/myinvoice@sha256:[0-9a-f]{64}$') {
    throw "Deployment requires an immutable kahanekdusan/myinvoice digest reference."
}

$expectedStack = [System.IO.Path]::GetFullPath('C:\docker\fakturace\stacks\production')
$resolvedStack = [System.IO.Path]::GetFullPath($StackDir)
if ($resolvedStack -ne $expectedStack) {
    throw "Refusing unexpected production stack path: $resolvedStack"
}

$composeFile = Join-Path $resolvedStack 'compose.yaml'
$envFile = Join-Path $resolvedStack '.env'
if (-not (Test-Path -LiteralPath $composeFile) -or -not (Test-Path -LiteralPath $envFile)) {
    throw 'Production compose.yaml or .env is missing.'
}

function Invoke-Checked {
    param([scriptblock]$Command, [string]$Failure)
    & $Command
    if ($LASTEXITCODE -ne 0) { throw $Failure }
}

function Set-AppImage {
    param([string]$Value)
    $lines = [System.Collections.Generic.List[string]](Get-Content -LiteralPath $envFile)
    $found = $false
    for ($i = 0; $i -lt $lines.Count; $i++) {
        if ($lines[$i] -match '^APP_IMAGE=') {
            $lines[$i] = "APP_IMAGE=$Value"
            $found = $true
            break
        }
    }
    if (-not $found) { $lines.Add("APP_IMAGE=$Value") }
    $temporary = "$envFile.tmp"
    [System.IO.File]::WriteAllLines($temporary, $lines, [System.Text.UTF8Encoding]::new($false))
    Move-Item -LiteralPath $temporary -Destination $envFile -Force
}

$previousImageLine = Get-Content -LiteralPath $envFile | Where-Object { $_ -match '^APP_IMAGE=' } | Select-Object -First 1
if (-not $previousImageLine) { throw 'APP_IMAGE is missing from production .env.' }
$previousImage = $previousImageLine.Substring('APP_IMAGE='.Length)

$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$backupDir = Join-Path (Join-Path $resolvedStack 'backups') $stamp
New-Item -ItemType Directory -Path $backupDir -Force | Out-Null

Write-Host "Creating pre-deploy backup in $backupDir"
Invoke-Checked { docker exec myinvoice-db-1 sh -lc 'rm -f /tmp/myinvoice-predeploy.sql /tmp/myinvoice-predeploy.sql.gz && mariadb-dump --single-transaction --quick --routines --triggers --events -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE" > /tmp/myinvoice-predeploy.sql && gzip -9 /tmp/myinvoice-predeploy.sql' } 'Database backup failed.'
Invoke-Checked { docker cp 'myinvoice-db-1:/tmp/myinvoice-predeploy.sql.gz' (Join-Path $backupDir 'database.sql.gz') } 'Database backup copy failed.'
Invoke-Checked { docker exec myinvoice-app-1 sh -lc 'rm -f /tmp/myinvoice-predeploy-data.tar.gz && tar -czf /tmp/myinvoice-predeploy-data.tar.gz -C /data .' } 'Application data backup failed.'
Invoke-Checked { docker cp 'myinvoice-app-1:/tmp/myinvoice-predeploy-data.tar.gz' (Join-Path $backupDir 'app-data.tar.gz') } 'Application data backup copy failed.'
docker exec myinvoice-db-1 sh -lc 'rm -f /tmp/myinvoice-predeploy.sql.gz' | Out-Null
docker exec myinvoice-app-1 sh -lc 'rm -f /tmp/myinvoice-predeploy-data.tar.gz' | Out-Null
Get-ChildItem -LiteralPath $backupDir -File | Get-FileHash -Algorithm SHA256 |
    ForEach-Object { '{0}  {1}' -f $_.Hash, $_.Path } |
    Set-Content -LiteralPath (Join-Path $backupDir 'SHA256SUMS.txt') -Encoding ascii

try {
    Write-Host "Deploying immutable image $ImageRef"
    Invoke-Checked { docker pull $ImageRef } 'Image pull failed.'
    Set-AppImage -Value $ImageRef
    Push-Location $resolvedStack
    try {
        Invoke-Checked { docker compose --env-file .env -f compose.yaml -p myinvoice up -d --no-deps app } 'Application recreate failed.'
        Invoke-Checked { docker compose --env-file .env -f compose.yaml -p myinvoice exec -T app php api/bin/migrate.php } 'Database migration failed.'
    } finally {
        Pop-Location
    }

    $healthy = $false
    for ($attempt = 1; $attempt -le 30; $attempt++) {
        try {
            $local = Invoke-WebRequest -Uri 'http://127.0.0.1:8088/' -TimeoutSec 5 -UseBasicParsing
            if ([int]$local.StatusCode -eq 200) { $healthy = $true; break }
        } catch {}
        Start-Sleep -Seconds 2
    }
    if (-not $healthy) { throw 'Local production health check did not return HTTP 200.' }

    $public = Invoke-WebRequest -Uri 'https://faktury.dusankahanek.cz/' -TimeoutSec 20 -UseBasicParsing
    if ([int]$public.StatusCode -ne 200) { throw "Public health check returned $($public.StatusCode)." }
    Write-Host 'Deployment completed: local and public HTTP 200.'
} catch {
    Write-Warning "Deployment failed; rolling application image back to $previousImage"
    Set-AppImage -Value $previousImage
    Push-Location $resolvedStack
    try {
        docker compose --env-file .env -f compose.yaml -p myinvoice up -d --no-deps app
    } finally {
        Pop-Location
    }
    throw
}
