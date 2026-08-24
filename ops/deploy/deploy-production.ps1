[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string]$ImageRef,

    [string]$StackDir = 'C:\docker\fakturace\stacks\production',

    [string]$GatewayDir = 'C:\docker\fakturace\stacks\gateway',

    [switch]$Preflight
)

$ErrorActionPreference = 'Stop'

if ($ImageRef -notmatch '^ghcr\.io/kahanekdusan/myinvoice@sha256:[0-9a-f]{64}$') {
    throw 'Deployment requires an immutable kahanekdusan/myinvoice digest reference.'
}

$expectedStack = [System.IO.Path]::GetFullPath('C:\docker\fakturace\stacks\production')
$resolvedStack = [System.IO.Path]::GetFullPath($StackDir)
if ($resolvedStack -ne $expectedStack) {
    throw "Refusing unexpected production stack path: $resolvedStack"
}

$expectedGateway = [System.IO.Path]::GetFullPath('C:\docker\fakturace\stacks\gateway')
$resolvedGateway = [System.IO.Path]::GetFullPath($GatewayDir)
if ($resolvedGateway -ne $expectedGateway) {
    throw "Refusing unexpected gateway path: $resolvedGateway"
}

$composeFile = Join-Path $resolvedStack 'compose.yaml'
$slotComposeFile = Join-Path $resolvedStack 'slot.compose.yaml'
$envFile = Join-Path $resolvedStack '.env'
$gatewayComposeFile = Join-Path $resolvedGateway 'compose.yaml'
$gatewayCaddyfile = Join-Path $resolvedGateway 'Caddyfile'
$gatewayUpstreamFile = Join-Path $resolvedGateway 'upstream.caddy'
$deploymentStateFile = Join-Path $resolvedStack 'active-deployment.json'

foreach ($required in @(
    $composeFile,
    $slotComposeFile,
    $envFile,
    $gatewayComposeFile,
    $gatewayCaddyfile,
    $gatewayUpstreamFile
)) {
    if (-not (Test-Path -LiteralPath $required)) {
        throw "Required production file is missing: $required"
    }
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

function Get-UpstreamContainer {
    param([string]$Content)
    if ($Content -notmatch '(?m)^reverse_proxy\s+(myinvoice-(?:app-1|blue|green)):80\s+\{') {
        throw 'Gateway upstream does not point to an approved production container.'
    }
    return $matches[1]
}

function New-UpstreamContent {
    param([ValidateSet('myinvoice-app-1', 'myinvoice-blue', 'myinvoice-green')][string]$Container)
    return @"
reverse_proxy ${Container}:80 {
`theader_up X-Forwarded-Proto https
`thealth_uri /api/health
`thealth_headers {
`t`tHost faktury.dusankahanek.cz
`t`tX-Forwarded-Proto https
`t}
`thealth_interval 10s
`thealth_timeout 2s
`tlb_try_duration 5s
`tlb_try_interval 250ms
}
"@
}

function Set-GatewayUpstream {
    param([string]$Content)
    $temporary = "$gatewayUpstreamFile.tmp"
    [System.IO.File]::WriteAllText($temporary, $Content, [System.Text.UTF8Encoding]::new($false))
    Move-Item -LiteralPath $temporary -Destination $gatewayUpstreamFile -Force
    Invoke-Checked {
        docker exec myinvoice-gateway caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile
    } 'Gateway configuration validation failed.'
    Invoke-Checked {
        docker exec myinvoice-gateway caddy reload --config /etc/caddy/Caddyfile --adapter caddyfile
    } 'Gateway reload failed.'
}

function Stop-ContainerCron {
    param([string]$Container)
    Invoke-Checked {
        docker exec $Container sh -lc 'pkill -x crond 2>/dev/null || true; pkill -x cron 2>/dev/null || true'
    } "Could not stop cron in $Container."
}

function Start-ContainerCron {
    param([string]$Container)
    Invoke-Checked {
        docker exec $Container sh -lc 'pkill -x crond 2>/dev/null || true; pkill -x cron 2>/dev/null || true; export -p > /etc/myinvoice-cron.env; chmod 0640 /etc/myinvoice-cron.env; chown root:www-data /etc/myinvoice-cron.env 2>/dev/null || true; if command -v cron >/dev/null 2>&1; then cron; elif command -v crond >/dev/null 2>&1; then crond; else exit 127; fi; pgrep -x cron >/dev/null 2>&1 || pgrep -x crond >/dev/null 2>&1'
    } "Could not start cron in $Container."
}

function Test-Http200 {
    param([string]$Uri, [int]$Attempts = 30)
    for ($attempt = 1; $attempt -le $Attempts; $attempt++) {
        try {
            $response = Invoke-WebRequest -Uri $Uri -TimeoutSec 5 -UseBasicParsing
            if ([int]$response.StatusCode -eq 200) { return $true }
        } catch {}
        Start-Sleep -Seconds 2
    }
    return $false
}

$previousImageLine = Get-Content -LiteralPath $envFile | Where-Object { $_ -match '^APP_IMAGE=' } | Select-Object -First 1
if (-not $previousImageLine) { throw 'APP_IMAGE is missing from production .env.' }
$previousImage = $previousImageLine.Substring('APP_IMAGE='.Length)

$previousUpstream = Get-Content -LiteralPath $gatewayUpstreamFile -Raw
$previousContainer = Get-UpstreamContainer -Content $previousUpstream
$candidateSlot = if ($previousContainer -eq 'myinvoice-blue') { 'green' } else { 'blue' }
$candidateContainer = "myinvoice-$candidateSlot"
$candidateProject = "myinvoice_$candidateSlot"

if ($Preflight) {
    $env:MYINVOICE_SLOT_IMAGE = $ImageRef
    $env:MYINVOICE_SLOT_CONTAINER = $candidateContainer
    $env:MYINVOICE_SLOT_NAME = $candidateSlot
    Push-Location $resolvedStack
    try {
        Invoke-Checked {
            docker compose --env-file .env -f slot.compose.yaml -p $candidateProject config --quiet
        } 'Candidate slot Compose preflight failed.'
    } finally {
        Pop-Location
        Remove-Item Env:MYINVOICE_SLOT_IMAGE -ErrorAction SilentlyContinue
        Remove-Item Env:MYINVOICE_SLOT_CONTAINER -ErrorAction SilentlyContinue
        Remove-Item Env:MYINVOICE_SLOT_NAME -ErrorAction SilentlyContinue
    }
    Invoke-Checked { docker inspect myinvoice-db-1 $previousContainer myinvoice-gateway | Out-Null } 'Required production container preflight failed.'
    Invoke-Checked { docker network inspect myinvoice_default public-gateway | Out-Null } 'Required Docker network preflight failed.'
    Invoke-Checked {
        docker exec myinvoice-gateway caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile
    } 'Gateway configuration preflight failed.'
    if (-not (Test-Http200 -Uri 'http://127.0.0.1:8087/' -Attempts 1)) {
        throw 'Local gateway preflight did not return HTTP 200.'
    }
    if (-not (Test-Http200 -Uri 'https://faktury.dusankahanek.cz/' -Attempts 1)) {
        throw 'Public production preflight did not return HTTP 200.'
    }
    Write-Host "Preflight completed: active=$previousContainer candidate=$candidateContainer local/public HTTP 200."
    return
}

$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$backupDir = Join-Path (Join-Path $resolvedStack 'backups') $stamp
New-Item -ItemType Directory -Path $backupDir -Force | Out-Null

Write-Host "Creating pre-deploy backup in $backupDir"
Invoke-Checked { docker exec myinvoice-db-1 sh -lc 'rm -f /tmp/myinvoice-predeploy.sql /tmp/myinvoice-predeploy.sql.gz && mariadb-dump --single-transaction --quick --routines --triggers --events -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE" > /tmp/myinvoice-predeploy.sql && gzip -9 /tmp/myinvoice-predeploy.sql' } 'Database backup failed.'
Invoke-Checked { docker cp 'myinvoice-db-1:/tmp/myinvoice-predeploy.sql.gz' (Join-Path $backupDir 'database.sql.gz') } 'Database backup copy failed.'
Invoke-Checked { docker exec $previousContainer sh -lc 'rm -f /tmp/myinvoice-predeploy-data.tar.gz && tar -czf /tmp/myinvoice-predeploy-data.tar.gz -C /data .' } 'Application data backup failed.'
Invoke-Checked { docker cp "${previousContainer}:/tmp/myinvoice-predeploy-data.tar.gz" (Join-Path $backupDir 'app-data.tar.gz') } 'Application data backup copy failed.'
docker exec myinvoice-db-1 sh -lc 'rm -f /tmp/myinvoice-predeploy.sql.gz' | Out-Null
docker exec $previousContainer sh -lc 'rm -f /tmp/myinvoice-predeploy-data.tar.gz' | Out-Null
Get-ChildItem -LiteralPath $backupDir -File | Get-FileHash -Algorithm SHA256 |
    ForEach-Object { '{0}  {1}' -f $_.Hash, $_.Path } |
    Set-Content -LiteralPath (Join-Path $backupDir 'SHA256SUMS.txt') -Encoding ascii

$gatewaySwitched = $false
$previousCronStopped = $false
$candidateCronStarted = $false
$appImageUpdated = $false

try {
    Write-Host "Preparing $candidateContainer from immutable image $ImageRef"
    Invoke-Checked { docker pull $ImageRef } 'Image pull failed.'

    $existingProject = docker inspect $candidateContainer --format '{{index .Config.Labels "com.docker.compose.project"}}' 2>$null
    if ($LASTEXITCODE -eq 0) {
        if ($existingProject -ne $candidateProject -or $candidateContainer -eq $previousContainer) {
            throw "Refusing to replace unexpected or active container $candidateContainer."
        }
        Invoke-Checked { docker rm -f $candidateContainer } "Could not replace inactive slot $candidateContainer."
    }

    $env:MYINVOICE_SLOT_IMAGE = $ImageRef
    $env:MYINVOICE_SLOT_CONTAINER = $candidateContainer
    $env:MYINVOICE_SLOT_NAME = $candidateSlot
    Push-Location $resolvedStack
    try {
        Invoke-Checked {
            docker compose --env-file .env -f slot.compose.yaml -p $candidateProject up -d --no-deps app
        } 'Candidate slot creation failed.'
    } finally {
        Pop-Location
        Remove-Item Env:MYINVOICE_SLOT_IMAGE -ErrorAction SilentlyContinue
        Remove-Item Env:MYINVOICE_SLOT_CONTAINER -ErrorAction SilentlyContinue
        Remove-Item Env:MYINVOICE_SLOT_NAME -ErrorAction SilentlyContinue
    }

    $candidateHealthy = $false
    for ($attempt = 1; $attempt -le 40; $attempt++) {
        $candidateHealth = docker inspect $candidateContainer --format '{{.State.Health.Status}}' 2>$null
        if ($LASTEXITCODE -eq 0 -and $candidateHealth -eq 'healthy') {
            $candidateHealthy = $true
            break
        }
        Start-Sleep -Seconds 2
    }
    if (-not $candidateHealthy) { throw "$candidateContainer did not become healthy." }

    Invoke-Checked {
        docker exec $candidateContainer php api/bin/migrate.php
    } 'Explicit candidate migration failed.'

    Set-GatewayUpstream -Content (New-UpstreamContent -Container $candidateContainer)
    $gatewaySwitched = $true

    if (-not (Test-Http200 -Uri 'http://127.0.0.1:8087/')) {
        throw 'Local gateway health check did not return HTTP 200.'
    }
    if (-not (Test-Http200 -Uri 'https://faktury.dusankahanek.cz/' -Attempts 10)) {
        throw 'Public health check did not return HTTP 200.'
    }

    Stop-ContainerCron -Container $previousContainer
    $previousCronStopped = $true
    Start-ContainerCron -Container $candidateContainer
    $candidateCronStarted = $true

    Set-AppImage -Value $ImageRef
    $appImageUpdated = $true

    $deploymentState = [ordered]@{
        active_slot = $candidateSlot
        active_container = $candidateContainer
        previous_container = $previousContainer
        image_digest = $ImageRef
        deployed_at_utc = (Get-Date).ToUniversalTime().ToString('o')
        backup_dir = $backupDir
    }
    $temporaryState = "$deploymentStateFile.tmp"
    $deploymentState | ConvertTo-Json | Set-Content -LiteralPath $temporaryState -Encoding utf8
    Move-Item -LiteralPath $temporaryState -Destination $deploymentStateFile -Force
    Write-Host "Blue/green deployment completed on ${candidateContainer}: local and public HTTP 200."
} catch {
    Write-Warning "Deployment failed; restoring gateway to $previousContainer."
    if ($gatewaySwitched) {
        try { Set-GatewayUpstream -Content $previousUpstream } catch { Write-Warning $_.Exception.Message }
    }
    if ($candidateCronStarted) {
        try { Stop-ContainerCron -Container $candidateContainer } catch { Write-Warning $_.Exception.Message }
    }
    if ($previousCronStopped) {
        try { Start-ContainerCron -Container $previousContainer } catch { Write-Warning $_.Exception.Message }
    }
    if ($appImageUpdated) {
        try { Set-AppImage -Value $previousImage } catch { Write-Warning $_.Exception.Message }
    }
    throw
}
