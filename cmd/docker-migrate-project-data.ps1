# Migrate MyInvoice Docker data between compose project volume prefixes.
# Copies both db-data and app-data volumes from source project to target project.
#
# Example:
#   .\cmd\docker-migrate-project-data.ps1 -SourceProject myinvoice_dev -TargetProject myinvoice
#
# Optional:
#   -NoBackup           Skip backup copy of current target volumes
#   -NoStartTarget      Do not run docker compose up -d db app at the end
#
# Notes:
# - Script stops running containers using source/target volumes before copy.
# - Source and target DB should not run during copy.
# - Target volume content is replaced.
[CmdletBinding()]
param(
    [string]$SourceProject,

    [string]$TargetProject,

    [switch]$NoBackup,
    [switch]$NoStartTarget,
    [switch]$ListProjects
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

function Test-VolumeExists([string]$Name) {
    $rows = @(& docker volume ls --filter "name=^${Name}$" --format '{{.Name}}' 2>$null)
    foreach ($row in $rows) {
        if ($row -eq $Name) { return $true }
    }
    return $false
}

function Get-MigrationProjectCandidates {
    $names = @(& docker volume ls --format '{{.Name}}' 2>$null)
    $dbProjects = @{}
    $appProjects = @{}

    foreach ($name in $names) {
        if ($name -match '^(.*)_db-data$') {
            $dbProjects[$Matches[1]] = $true
            continue
        }
        if ($name -match '^(.*)_app-data$') {
            $appProjects[$Matches[1]] = $true
        }
    }

    $result = @()
    foreach ($key in $dbProjects.Keys) {
        if ($appProjects.ContainsKey($key)) {
            $result += $key
        }
    }

    return @($result | Sort-Object -Unique)
}

function Ensure-VolumeExists([string]$Name) {
    if (-not (Test-VolumeExists $Name)) {
        Write-Host "==> Creating volume: $Name"
        & docker volume create $Name | Out-Null
        if ($LASTEXITCODE -ne 0) { throw "Failed to create volume $Name" }
    }
}

function Stop-ContainersUsingVolume([string]$VolumeName) {
    $ids = @(& docker ps -q --filter "volume=$VolumeName" 2>$null)
    if ($ids.Count -gt 0) {
        Write-Host "==> Stopping containers using volume $VolumeName"
        & docker stop $ids | Out-Null
        if ($LASTEXITCODE -ne 0) { throw "Failed to stop containers using volume $VolumeName" }
    }
}

function Copy-Volume([string]$FromVolume, [string]$ToVolume) {
    Write-Host "==> Copying $FromVolume -> $ToVolume"
    & docker run --rm `
        -v "${FromVolume}:/from:ro" `
        -v "${ToVolume}:/to" `
        alpine sh -c "rm -rf /to/* /to/.[!.]* /to/..?* 2>/dev/null || true; cp -a /from/. /to/"
    if ($LASTEXITCODE -ne 0) { throw "Copy failed: $FromVolume -> $ToVolume" }
}

function Backup-TargetVolume([string]$TargetVolume, [string]$BackupVolume) {
    Ensure-VolumeExists $BackupVolume
    Write-Host "==> Backing up $TargetVolume -> $BackupVolume"
    & docker run --rm `
        -v "${TargetVolume}:/from:ro" `
        -v "${BackupVolume}:/to" `
        alpine sh -c "rm -rf /to/* /to/.[!.]* /to/..?* 2>/dev/null || true; cp -a /from/. /to/"
    if ($LASTEXITCODE -ne 0) { throw "Backup failed: $TargetVolume -> $BackupVolume" }
}

Assert-Docker

if ($ListProjects) {
    $candidates = Get-MigrationProjectCandidates
    if ($candidates.Count -eq 0) {
        Write-Host "No project candidates found (expected *_db-data and *_app-data pairs)."
    } else {
        Write-Host "Available migration project prefixes:"
        foreach ($c in $candidates) { Write-Host "  - $c" }
    }
    exit 0
}

if ([string]::IsNullOrWhiteSpace($SourceProject) -or [string]::IsNullOrWhiteSpace($TargetProject)) {
    throw "Both -SourceProject and -TargetProject are required (or use -ListProjects)."
}

$sourceDb = "${SourceProject}_db-data"
$sourceApp = "${SourceProject}_app-data"
$targetDb = "${TargetProject}_db-data"
$targetApp = "${TargetProject}_app-data"

Write-Host "==> Source project: $SourceProject"
Write-Host "==> Target project: $TargetProject"
Write-Host "    Source volumes: $sourceDb, $sourceApp"
Write-Host "    Target volumes: $targetDb, $targetApp"
Write-Host ""

if ((-not (Test-VolumeExists $sourceDb)) -or (-not (Test-VolumeExists $sourceApp))) {
    $candidates = Get-MigrationProjectCandidates
    $msg = "Source project '$SourceProject' not found. Expected volumes: $sourceDb and $sourceApp."
    if ($candidates.Count -gt 0) {
        $msg += " Available project prefixes: " + ($candidates -join ', ') + "."
    }
    $msg += " Tip: run .\\cmd\\docker-migrate-project-data.ps1 -ListProjects"
    throw $msg
}

Ensure-VolumeExists $targetDb
Ensure-VolumeExists $targetApp

# Stop any containers that may keep source/target data active during copy.
Stop-ContainersUsingVolume $sourceDb
Stop-ContainersUsingVolume $sourceApp
Stop-ContainersUsingVolume $targetDb
Stop-ContainersUsingVolume $targetApp

$backupDb = $null
$backupApp = $null
if (-not $NoBackup) {
    $stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
    $backupDb = "${targetDb}-backup-$stamp"
    $backupApp = "${targetApp}-backup-$stamp"
    Backup-TargetVolume $targetDb $backupDb
    Backup-TargetVolume $targetApp $backupApp
    Write-Host ""
}

Copy-Volume $sourceDb $targetDb
Copy-Volume $sourceApp $targetApp
Write-Host ""

if (-not $NoStartTarget) {
    if (Test-Path (Join-Path $ProjectRoot 'docker-compose.yml')) {
        Write-Host "==> Starting target stack (db + app)"
        & docker compose up -d db app
        if ($LASTEXITCODE -ne 0) { throw "docker compose up failed for target stack" }
    } else {
        Write-Warning "docker-compose.yml not found in $ProjectRoot; skip start"
    }
}

Write-Host ""
Write-Host "============================================================"
Write-Host " Migration finished."
if ($backupDb -and $backupApp) {
    Write-Host " Backups created:"
    Write-Host "   $backupDb"
    Write-Host "   $backupApp"
}
Write-Host "============================================================"
