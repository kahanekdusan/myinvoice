# Export/import MyInvoice MariaDB dump between PCs.
#
# Examples:
#   .\cmd\docker-db-sync.ps1 -Action export -ProjectName myinvoice_dev -SqlPath .\myinvoice-dev.sql
#   .\cmd\docker-db-sync.ps1 -Action import -ProjectName myinvoice -SqlPath .\myinvoice-dev.sql -StartDb
#   .\cmd\docker-db-sync.ps1 -Action list-projects
[CmdletBinding()]
param(
    [ValidateSet('export', 'import', 'list-projects')]
    [string]$Action = 'list-projects',

    [string]$ProjectName,
    [string]$SqlPath,
    [switch]$StartDb,
    [switch]$NoRestartApp
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$ProjectRoot = Resolve-Path (Join-Path $PSScriptRoot '..')
Set-Location $ProjectRoot

function Assert-Docker {
    if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
        throw "docker not found in PATH"
    }
    & docker compose version > $null 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "'docker compose' (v2) plugin required"
    }
}

function Get-DefaultProjectName {
    if ($env:COMPOSE_PROJECT_NAME) { return $env:COMPOSE_PROJECT_NAME }
    return ((Split-Path -Leaf $ProjectRoot).ToLower() -replace '[^a-z0-9_-]', '')
}

function Get-ComposeProjectCandidates {
    $rows = @(& docker volume ls --format '{{.Name}}' 2>$null)
    $db = @{}
    $app = @{}
    foreach ($row in $rows) {
        if ($row -match '^(.*)_db-data$') { $db[$Matches[1]] = $true; continue }
        if ($row -match '^(.*)_app-data$') { $app[$Matches[1]] = $true }
    }

    $result = @()
    foreach ($k in $db.Keys) {
        if ($app.ContainsKey($k)) { $result += $k }
    }
    return @($result | Sort-Object -Unique)
}

function Get-ContainerName([string]$Project, [string]$Service) {
    $name = (& docker ps -a `
        --filter "label=com.docker.compose.project=$Project" `
        --filter "label=com.docker.compose.service=$Service" `
        --format '{{.Names}}' | Select-Object -First 1)
    if ($name) { return $name.Trim() }
    return $null
}

function Test-ContainerRunning([string]$ContainerName) {
    $state = (& docker inspect -f '{{.State.Running}}' $ContainerName 2>$null | Select-Object -First 1)
    return ($state -eq 'true')
}

function Ensure-DbContainer([string]$Project, [switch]$AllowStart) {
    $dbContainer = Get-ContainerName $Project 'db'

    if (-not $dbContainer) {
        if (-not $AllowStart) {
            throw "DB container not found for project '$Project'. Use -StartDb to create/start it."
        }

        Write-Host "==> DB container not found. Starting db service via docker compose -p $Project up -d db"
        & docker compose -p $Project up -d db
        if ($LASTEXITCODE -ne 0) { throw "docker compose up -d db failed for project $Project" }
        $dbContainer = Get-ContainerName $Project 'db'
        if (-not $dbContainer) { throw "DB container still not found for project '$Project' after start." }
    }

    if (-not (Test-ContainerRunning $dbContainer)) {
        if (-not $AllowStart) {
            throw "DB container '$dbContainer' is not running. Use -StartDb."
        }
        Write-Host "==> Starting DB container $dbContainer"
        & docker start $dbContainer | Out-Null
        if ($LASTEXITCODE -ne 0) { throw "Failed to start DB container $dbContainer" }
    }

    return $dbContainer
}

function Export-Db([string]$Project, [string]$OutputPath, [switch]$AllowStart) {
    $dbContainer = Ensure-DbContainer -Project $Project -AllowStart:$AllowStart

    Write-Host "==> Exporting database from project '$Project' (container: $dbContainer)"
    & docker exec $dbContainer sh -lc 'mariadb-dump --single-transaction --routines --triggers --events "$MARIADB_DATABASE" -u"$MARIADB_USER" -p"$MARIADB_PASSWORD"' > $OutputPath
    if ($LASTEXITCODE -ne 0) { throw "mariadb-dump failed" }

    if (-not (Test-Path $OutputPath)) { throw "Dump file was not created: $OutputPath" }
    $size = (Get-Item $OutputPath).Length
    if ($size -le 0) { throw "Dump file is empty: $OutputPath" }

    Write-Host "==> Export completed: $OutputPath ($size bytes)"
}

function Import-Db([string]$Project, [string]$InputPath, [switch]$AllowStart, [switch]$SkipRestartApp) {
    if (-not (Test-Path $InputPath)) { throw "SQL file not found: $InputPath" }

    $dbContainer = Ensure-DbContainer -Project $Project -AllowStart:$AllowStart
    $remote = '/tmp/myinvoice-import.sql'

    Write-Host "==> Copying SQL file into container: $InputPath -> ${dbContainer}:$remote"
    & docker cp $InputPath "${dbContainer}:$remote"
    if ($LASTEXITCODE -ne 0) { throw "docker cp failed" }

    Write-Host "==> Importing SQL into project '$Project'"
    & docker exec $dbContainer sh -lc 'mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE" < /tmp/myinvoice-import.sql && rm -f /tmp/myinvoice-import.sql'
    if ($LASTEXITCODE -ne 0) { throw "SQL import failed" }

    $count = (& docker exec $dbContainer sh -lc 'mariadb -Nse "SELECT COUNT(*) FROM invoices" "$MARIADB_DATABASE" -u"$MARIADB_USER" -p"$MARIADB_PASSWORD"' 2>$null | Select-Object -First 1)
    if ($LASTEXITCODE -eq 0 -and $count) {
        Write-Host "==> Import verification: invoices=$count"
    } else {
        Write-Host "==> Import completed (invoice count check skipped)"
    }

    if (-not $SkipRestartApp) {
        $appContainer = Get-ContainerName $Project 'app'
        if ($appContainer) {
            if (Test-ContainerRunning $appContainer) {
                Write-Host "==> Restarting app container: $appContainer"
                & docker restart $appContainer | Out-Null
                if ($LASTEXITCODE -ne 0) { throw "Failed to restart app container $appContainer" }
            } else {
                Write-Host "==> App container exists but is stopped: $appContainer (restart skipped)"
            }
        } else {
            Write-Host "==> App container not found for project '$Project' (restart skipped)"
        }
    }
}

Assert-Docker

if (-not $ProjectName) {
    $ProjectName = Get-DefaultProjectName
}

if (-not $SqlPath) {
    $stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
    if ($Action -eq 'export') {
        $SqlPath = Join-Path $ProjectRoot ("${ProjectName}-${stamp}.sql")
    } elseif ($Action -eq 'import') {
        throw "-SqlPath is required for import action"
    }
}

switch ($Action) {
    'list-projects' {
        $candidates = Get-ComposeProjectCandidates
        if ($candidates.Count -eq 0) {
            Write-Host "No project candidates found (expected *_db-data and *_app-data pairs)."
        } else {
            Write-Host "Available compose project prefixes:"
            foreach ($p in $candidates) { Write-Host "  - $p" }
        }
    }
    'export' {
        Export-Db -Project $ProjectName -OutputPath $SqlPath -AllowStart:$StartDb
    }
    'import' {
        Import-Db -Project $ProjectName -InputPath $SqlPath -AllowStart:$StartDb -SkipRestartApp:$NoRestartApp
    }
}
