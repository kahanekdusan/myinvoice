# Bootstrap and operate two local Docker variants from one git repository:
#   - development working copy (source build, docker-compose.yml)
#   - upstream baseline copy (GHCR image, docker-compose.production.yml)
#
# Usage:
#   .\cmd\docker-dual-local.ps1                # default action: up
#   .\cmd\docker-dual-local.ps1 -Action status
#   .\cmd\docker-dual-local.ps1 -Action down
[CmdletBinding()]
param(
    [ValidateSet('up', 'down', 'status')]
    [string]$Action = 'up',

    [string]$UpstreamWorktreeName = 'myinvoice-upstream',
    [int]$DefaultUpstreamAppPort = 8090,
    [int]$DefaultUpstreamDbPort = 3310
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$ProjectRoot = Resolve-Path (Join-Path $PSScriptRoot '..')
Set-Location $ProjectRoot
$UpstreamWorktreePath = Join-Path (Split-Path $ProjectRoot -Parent) $UpstreamWorktreeName

function New-RandomToken([int]$Bytes = 24) {
    $buf = New-Object byte[] $Bytes
    [System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($buf)
    return ([Convert]::ToBase64String($buf) -replace '[+/=]', '').Substring(0, [math]::Min($Bytes + 4, 32))
}

function Read-EnvFile([string]$Path) {
    $map = @{}
    if (-not (Test-Path $Path)) { return $map }

    Get-Content $Path | ForEach-Object {
        if ($_ -match '^\s*([A-Z_][A-Z0-9_]*)\s*=\s*(.*)\s*$') {
            $map[$Matches[1]] = $Matches[2]
        }
    }
    return $map
}

function Set-EnvKey([string]$Path, [string]$Key, [string]$Value) {
    $lines = @()
    if (Test-Path $Path) { $lines = @(Get-Content $Path) }

    $pattern = '^\s*' + [regex]::Escape($Key) + '\s*='
    $updated = $false
    for ($i = 0; $i -lt $lines.Count; $i++) {
        if ($lines[$i] -match $pattern) {
            $lines[$i] = "$Key=$Value"
            $updated = $true
            break
        }
    }
    if (-not $updated) {
        $lines += "$Key=$Value"
    }

    Set-Content -Encoding UTF8 $Path -Value $lines
}

function Get-IntOrDefault([hashtable]$Map, [string]$Key, [int]$Default) {
    if ($Map.ContainsKey($Key) -and $Map[$Key] -match '^\d+$') {
        return [int]$Map[$Key]
    }
    return $Default
}

function Test-WorktreeRegistered([string]$Path) {
    $target = [System.IO.Path]::GetFullPath($Path).TrimEnd('\\')
    $rows = & git worktree list --porcelain
    foreach ($row in $rows) {
        if ($row -like 'worktree *') {
            $wt = [System.IO.Path]::GetFullPath(($row.Substring(9).Trim())).TrimEnd('\\')
            if ($wt -ieq $target) { return $true }
        }
    }
    return $false
}

function Update-UpstreamWorktree {
    if (Test-Path $UpstreamWorktreePath) {
        if (-not (Test-WorktreeRegistered $UpstreamWorktreePath)) {
            throw "Path '$UpstreamWorktreePath' already exists but is not a registered git worktree."
        }
    } else {
        Write-Host "==> Creating upstream worktree at $UpstreamWorktreePath"
        & git worktree add --detach $UpstreamWorktreePath upstream/master
        if ($LASTEXITCODE -ne 0) { throw "git worktree add failed" }
    }

    $dirty = & git -C $UpstreamWorktreePath status --porcelain
    if ($dirty) {
        throw "Upstream worktree has uncommitted changes. Clean it first: $UpstreamWorktreePath"
    }

    & git -C $UpstreamWorktreePath fetch --all --prune
    if ($LASTEXITCODE -ne 0) { throw "git fetch failed in upstream worktree" }

    & git -C $UpstreamWorktreePath checkout --detach upstream/master
    if ($LASTEXITCODE -ne 0) { throw "Failed to checkout upstream/master in upstream worktree" }
}

function Set-DevelopmentBranch {
    $current = (& git rev-parse --abbrev-ref HEAD).Trim()
    if ($current -eq 'development') { return }

    $dirty = & git status --porcelain
    if ($dirty) {
        throw "Current branch '$current' has uncommitted changes. Commit/stash before switching to development."
    }

    & git show-ref --verify --quiet refs/heads/development
    if ($LASTEXITCODE -eq 0) {
        & git checkout development
    } else {
        & git checkout -b development origin/development
    }
    if ($LASTEXITCODE -ne 0) { throw "Unable to checkout development branch" }
}

function Set-UpstreamEnv {
    $envPath = Join-Path $UpstreamWorktreePath '.env'

    if (-not (Test-Path $envPath)) {
        Write-Host "==> Creating upstream .env"
        @"
# MyInvoice upstream baseline env (gitignored)
APP_PORT=$DefaultUpstreamAppPort
APP_PORT_PROD=$DefaultUpstreamAppPort
DB_PORT=$DefaultUpstreamDbPort
DB_PORT_PROD=$DefaultUpstreamDbPort
DB_NAME=myinvoice
DB_USER=myinvoice
DB_ROOT_PASSWORD=$(New-RandomToken 24)
DB_PASSWORD=$(New-RandomToken 24)
"@ | Set-Content -Encoding UTF8 -NoNewline $envPath
    }

    $envMap = Read-EnvFile $envPath
    if (-not $envMap.ContainsKey('APP_PORT')) {
        Set-EnvKey $envPath 'APP_PORT' "$DefaultUpstreamAppPort"
        $envMap['APP_PORT'] = "$DefaultUpstreamAppPort"
    }
    if (-not $envMap.ContainsKey('APP_PORT_PROD')) {
        Set-EnvKey $envPath 'APP_PORT_PROD' "$($envMap['APP_PORT'])"
        $envMap['APP_PORT_PROD'] = "$($envMap['APP_PORT'])"
    }
    if (-not $envMap.ContainsKey('DB_PORT')) {
        Set-EnvKey $envPath 'DB_PORT' "$DefaultUpstreamDbPort"
        $envMap['DB_PORT'] = "$DefaultUpstreamDbPort"
    }
    if (-not $envMap.ContainsKey('DB_PORT_PROD')) {
        Set-EnvKey $envPath 'DB_PORT_PROD' "$($envMap['DB_PORT'])"
        $envMap['DB_PORT_PROD'] = "$($envMap['DB_PORT'])"
    }
    if (-not $envMap.ContainsKey('DB_NAME')) {
        Set-EnvKey $envPath 'DB_NAME' 'myinvoice'
    }
    if (-not $envMap.ContainsKey('DB_USER')) {
        Set-EnvKey $envPath 'DB_USER' 'myinvoice'
    }
    if (-not $envMap.ContainsKey('DB_ROOT_PASSWORD')) {
        Set-EnvKey $envPath 'DB_ROOT_PASSWORD' (New-RandomToken 24)
    }
    if (-not $envMap.ContainsKey('DB_PASSWORD')) {
        Set-EnvKey $envPath 'DB_PASSWORD' (New-RandomToken 24)
    }
}

function Update-MasterMirror {
    & git branch --set-upstream-to=upstream/master master > $null 2>&1
    $ahead = [int](& git rev-list --count upstream/master..master)
    if ($ahead -gt 0) {
        Write-Warning "Local master has $ahead commit(s) not in upstream/master. Auto-reset skipped."
        return
    }

    & git branch -f master upstream/master > $null 2>&1
}

function Start-DevelopmentStack {
    Write-Host "==> Starting development stack"
    $envPath = Join-Path $ProjectRoot '.env'
    $cfgPath = Join-Path $ProjectRoot 'cfg.docker.php'
    if (-not (Test-Path $envPath) -or -not (Test-Path $cfgPath)) {
        & (Join-Path $ProjectRoot 'cmd\docker-install.ps1') -Build
        if ($LASTEXITCODE -ne 0) { throw "docker-install.ps1 -Build failed" }
        return
    }

    & docker compose up -d db app
    if ($LASTEXITCODE -ne 0) { throw "docker compose up failed for development stack" }
}

function Start-UpstreamStack {
    Write-Host "==> Starting upstream baseline stack"
    & (Join-Path $UpstreamWorktreePath 'cmd\docker-ghcr.ps1')
    if ($LASTEXITCODE -ne 0) { throw "docker-ghcr.ps1 failed in upstream worktree" }
}

function Stop-Stacks {
    Write-Host "==> Stopping development stack"
    & docker compose down

    if (Test-Path (Join-Path $UpstreamWorktreePath 'docker-compose.production.yml')) {
        Write-Host "==> Stopping upstream baseline stack"
        & docker compose -f (Join-Path $UpstreamWorktreePath 'docker-compose.production.yml') down
    }
}

function Show-Status {
    $devEnv = Read-EnvFile (Join-Path $ProjectRoot '.env')
    $devAppPort = Get-IntOrDefault $devEnv 'APP_PORT' 8080

    Write-Host ""
    Write-Host "Development URL: http://localhost:$devAppPort"

    $upstreamComposePath = Join-Path $UpstreamWorktreePath 'docker-compose.production.yml'
    if (Test-Path $upstreamComposePath) {
        $upEnv = Read-EnvFile (Join-Path $UpstreamWorktreePath '.env')
        $upAppPort = Get-IntOrDefault $upEnv 'APP_PORT_PROD' (Get-IntOrDefault $upEnv 'APP_PORT' $DefaultUpstreamAppPort)
        Write-Host "Upstream URL:    http://localhost:$upAppPort"
    } else {
        Write-Host "Upstream URL:    <not initialized yet>"
    }

    Write-Host ""
    Write-Host "==> Development compose ps"
    & docker compose ps

    if (Test-Path $upstreamComposePath) {
        Write-Host ""
        Write-Host "==> Upstream compose ps"
        & docker compose -f $upstreamComposePath ps
    }
}

if (-not (Get-Command git -ErrorAction SilentlyContinue)) { throw "git not found in PATH" }
if (-not (Get-Command docker -ErrorAction SilentlyContinue)) { throw "docker not found in PATH" }
& docker compose version > $null 2>&1
if ($LASTEXITCODE -ne 0) { throw "'docker compose' (v2) plugin required" }

switch ($Action) {
    'up' {
        & git fetch --all --prune
        if ($LASTEXITCODE -ne 0) { throw "git fetch failed" }

        Update-MasterMirror
        Set-DevelopmentBranch
        Update-UpstreamWorktree
        Set-UpstreamEnv

        $devEnv = Read-EnvFile (Join-Path $ProjectRoot '.env')
        $devAppPort = Get-IntOrDefault $devEnv 'APP_PORT' 8080
        $devDbPort = Get-IntOrDefault $devEnv 'DB_PORT' 3307

        $upEnv = Read-EnvFile (Join-Path $UpstreamWorktreePath '.env')
        $upAppPort = Get-IntOrDefault $upEnv 'APP_PORT_PROD' (Get-IntOrDefault $upEnv 'APP_PORT' $DefaultUpstreamAppPort)
        $upDbPort = Get-IntOrDefault $upEnv 'DB_PORT_PROD' (Get-IntOrDefault $upEnv 'DB_PORT' $DefaultUpstreamDbPort)

        if ($upAppPort -eq $devAppPort) {
            throw "Port conflict: development APP_PORT=$devAppPort and upstream APP_PORT_PROD=$upAppPort. Fix upstream .env in $UpstreamWorktreePath"
        }
        if ($upDbPort -eq $devDbPort) {
            throw "Port conflict: development DB_PORT=$devDbPort and upstream DB_PORT_PROD=$upDbPort. Fix upstream .env in $UpstreamWorktreePath"
        }

        Start-DevelopmentStack
        Start-UpstreamStack
        Show-Status
    }
    'down' {
        Stop-Stacks
    }
    'status' {
        Show-Status
    }
}
