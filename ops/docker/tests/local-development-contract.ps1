[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$Root = (Resolve-Path (Join-Path $PSScriptRoot '..\..\..')).Path
$Temp = Join-Path ([IO.Path]::GetTempPath()) ('myinvoice-contract-' + [Guid]::NewGuid().ToString('N'))
$FakeBin = Join-Path $Temp 'bin'
$Log = Join-Path $Temp 'docker.log'
$Mirror = Join-Path $Temp 'mirror'
$Target = Join-Path $Mirror 'cmd\docker-local.ps1'
$RuntimeBase = Join-Path $Mirror '.docker-local'
$TouchedEnvironment = @('PATH', 'APP_PORT', 'DB_PASSWORD', 'COMPOSE_PROJECT_NAME', 'DOCKER_CONTEXT', 'FAKE_GENERATION', 'FAKE_VOLUME_MODE', 'FAKE_DOCKER_LOG')
$OriginalEnvironment = @{}
foreach ($key in $TouchedEnvironment) {
    $item = Get-Item -LiteralPath ("Env:" + $key) -ErrorAction SilentlyContinue
    if ($null -ne $item) { $OriginalEnvironment[$key] = $item.Value }
}

try {
    New-Item -ItemType Directory -Path $FakeBin | Out-Null
    New-Item -ItemType Directory -Path (Join-Path $Mirror 'cmd') | Out-Null
    New-Item -ItemType Directory -Path (Join-Path $Mirror 'ops\docker') | Out-Null
    Copy-Item -LiteralPath (Join-Path $Root 'cmd\docker-local.ps1') -Destination $Target
    Copy-Item -LiteralPath (Join-Path $Root 'ops\docker\compose.local.yaml') -Destination (Join-Path $Mirror 'ops\docker\compose.local.yaml')
    $fakeDocker = @'
@echo off
set "AP=unset"
set "DP=unset"
set "CP=unset"
if defined APP_PORT set "AP=%APP_PORT%"
if defined DB_PASSWORD set "DP=%DB_PASSWORD%"
if defined COMPOSE_PROJECT_NAME set "CP=%COMPOSE_PROJECT_NAME%"
echo APP_PORT=%AP%;DB_PASSWORD=%DP%;COMPOSE_PROJECT_NAME=%CP%;ARGS=%*>>"%FAKE_DOCKER_LOG%"
if "%1 %2"=="context show" echo default
if "%1 %2"=="context inspect" echo npipe:////./pipe/docker_engine
if "%1 %2"=="volume inspect" (
  if "%3"=="--format" (
    echo %4| findstr /C:"generation" >nul
    if not errorlevel 1 (
      if "%FAKE_VOLUME_MODE%"=="mismatch" (echo wrong-generation) else (echo %FAKE_GENERATION%)
    ) else (
      echo local-development
    )
  )
  exit /b 0
)
exit /b 0
'@
    [IO.File]::WriteAllText((Join-Path $FakeBin 'docker.cmd'), $fakeDocker, (New-Object Text.ASCIIEncoding))
    $env:FAKE_DOCKER_LOG = $Log
    $env:PATH = $FakeBin + [IO.Path]::PathSeparator + $env:PATH
    $env:APP_PORT = '18091'
    $env:DB_PASSWORD = 'production-value'
    $env:COMPOSE_PROJECT_NAME = 'production-project'

    $output = & $Target -Action config
    if ($LASTEXITCODE -ne 0) { throw 'PowerShell bootstrap failed.' }
    if ($output -notcontains 'LOCAL_DEV_PREFLIGHT=GO;URL=http://localhost:8090') { throw 'Runtime APP_PORT was not authoritative.' }
    $logLines = [IO.File]::ReadAllLines($Log)
    if ($logLines | Where-Object { $_ -notmatch '^APP_PORT=unset;DB_PASSWORD=unset;COMPOSE_PROJECT_NAME=unset;ARGS=' }) {
        throw 'Protected host environment reached Docker Compose.'
    }
    if (-not ($logLines | Where-Object { $_ -match 'ARGS=compose --project-name myinvoice_dev --env-file' })) {
        throw 'Compose project name or runtime env was not explicit.'
    }

    $runtimeEnv = Join-Path $RuntimeBase 'development\runtime.env'
    $lines = [IO.File]::ReadAllLines($runtimeEnv)
    foreach ($key in @('APP_PORT', 'DB_PORT', 'DB_ROOT_PASSWORD', 'DB_PASSWORD', 'MYINVOICE_PEPPER', 'MYINVOICE_SECRET_KEY', 'LOCAL_GENERATION_ID')) {
        if (($lines | Where-Object { $_ -match ('^' + [regex]::Escape($key) + '=') }).Count -ne 1) {
            throw "Runtime key $key must occur exactly once."
        }
    }

    $env:FAKE_GENERATION = (($lines | Where-Object { $_ -match '^LOCAL_GENERATION_ID=' }) -split '=', 2)[1]
    $env:FAKE_VOLUME_MODE = 'trusted'
    [IO.File]::WriteAllText($Log, '', (New-Object Text.UTF8Encoding($false)))
    $upOutput = & $Target -Action up
    if ($upOutput -notcontains 'LOCAL_DEV_UP=PASS;URL=http://localhost:8090') { throw 'Trusted volume up path failed.' }
    $upLog = [IO.File]::ReadAllText($Log)
    if ($upLog -notmatch ' up -d --build --wait app db') { throw 'Compose up was not invoked.' }
    if ([IO.File]::ReadAllLines($Log) | Where-Object { $_ -notmatch '^APP_PORT=unset;DB_PASSWORD=unset;COMPOSE_PROJECT_NAME=unset;ARGS=' }) {
        throw 'Protected host environment reached a Docker volume/up command.'
    }

    $env:FAKE_VOLUME_MODE = 'mismatch'
    [IO.File]::WriteAllText($Log, '', (New-Object Text.UTF8Encoding($false)))
    try {
        & $Target -Action up *> $null
        throw 'Untrusted volume was unexpectedly accepted.'
    } catch {
        if ($_.Exception.Message -notmatch 'Refusing existing untrusted volume') { throw "Unexpected volume rejection error: $($_.Exception.Message)" }
    }
    if ([IO.File]::ReadAllText($Log) -match ' up -d --build --wait app db') { throw 'Compose up ran after volume identity rejection.' }

    if ($env:OS -eq 'Windows_NT') {
        $wideAcl = Get-Acl -LiteralPath $runtimeEnv
        $everyone = [System.Security.Principal.SecurityIdentifier]::new('S-1-1-0')
        $wideAcl.AddAccessRule([System.Security.AccessControl.FileSystemAccessRule]::new($everyone, 'Read', 'Allow'))
        Set-Acl -LiteralPath $runtimeEnv -AclObject $wideAcl
        & $Target -Action config *> $null

        $allowedSids = @(
            [System.Security.Principal.WindowsIdentity]::GetCurrent().User.Value,
            'S-1-5-18',
            'S-1-5-32-544'
        )
        foreach ($aclPath in @($RuntimeBase, (Join-Path $RuntimeBase 'development'), $runtimeEnv)) {
            $acl = Get-Acl -LiteralPath $aclPath
            if (-not $acl.AreAccessRulesProtected) { throw "Runtime path still inherits ACLs: $aclPath" }
            foreach ($rule in $acl.Access) {
                $sid = $rule.IdentityReference.Translate([System.Security.Principal.SecurityIdentifier]).Value
                if ($rule.AccessControlType -ne 'Allow' -or $sid -notin $allowedSids) {
                    throw "Unexpected runtime ACL for $sid on $aclPath."
                }
            }
            foreach ($sid in $allowedSids) {
                if (-not ($acl.Access | Where-Object { $_.IdentityReference.Translate([System.Security.Principal.SecurityIdentifier]).Value -eq $sid -and $_.FileSystemRights.ToString().Contains('FullControl') })) {
                    throw "Missing FullControl runtime ACL for $sid on $aclPath."
                }
            }
        }
    }

    $env:DOCKER_CONTEXT = 'remote-production'
    try {
        & $Target -Action config *> $null
        throw 'Remote Docker context was unexpectedly accepted.'
    } catch {
        if ($_.Exception.Message -notmatch 'DOCKER_HOST/DOCKER_CONTEXT must be unset') { throw "Unexpected remote-context error: $($_.Exception.Message)" }
    } finally {
        Remove-Item Env:DOCKER_CONTEXT -ErrorAction SilentlyContinue
    }

    [IO.File]::WriteAllLines($runtimeEnv, ($lines + 'APP_PORT=18092'), (New-Object Text.UTF8Encoding($false)))
    try {
        & $Target -Action config *> $null
        throw 'Duplicate runtime key was unexpectedly accepted.'
    } catch {
        if ($_.Exception.Message -notmatch 'occurs more than once') { throw "Unexpected duplicate-key error: $($_.Exception.Message)" }
    }
    [IO.File]::WriteAllLines($runtimeEnv, ($lines | ForEach-Object { if ($_ -match '^APP_PORT=') { 'APP_PORT=invalid' } else { $_ } }), (New-Object Text.UTF8Encoding($false)))
    try {
        & $Target -Action config *> $null
        throw 'Invalid runtime port was unexpectedly accepted.'
    } catch {
        if ($_.Exception.Message -notmatch 'APP_PORT must be an integer') { throw "Unexpected invalid-port error: $($_.Exception.Message)" }
    }

    [IO.File]::WriteAllLines($runtimeEnv, ($lines + 'COMPOSE_PROJECT_NAME=production'), (New-Object Text.UTF8Encoding($false)))
    try {
        & $Target -Action config *> $null
        throw 'Unknown runtime key was unexpectedly accepted.'
    } catch {
        if ($_.Exception.Message -notmatch 'Unknown runtime key COMPOSE_PROJECT_NAME') { throw "Unexpected unknown-key error: $($_.Exception.Message)" }
    }

    [IO.File]::WriteAllLines($runtimeEnv, $lines, (New-Object Text.UTF8Encoding($false)))
    Remove-Item -LiteralPath $RuntimeBase -Recurse -Force
    $outside = Join-Path $Temp 'outside'
    New-Item -ItemType Directory -Path $outside | Out-Null
    New-Item -ItemType Junction -Path $RuntimeBase -Target $outside | Out-Null
    foreach ($junctionAction in @('init', 'down', 'status')) {
        try {
            & $Target -Action $junctionAction *> $null
            throw "Junction runtime base was unexpectedly accepted by $junctionAction."
        } catch {
            if ($_.Exception.Message -notmatch 'must not be a symlink or reparse point') {
                throw "Unexpected junction error for ${junctionAction}: $($_.Exception.Message)"
            }
        }
    }
    if (Get-ChildItem -LiteralPath $outside -Force) { throw 'Secret data was written through a junction.' }

    Write-Output 'local-development-contract-powershell: PASS'
} finally {
    foreach ($key in $TouchedEnvironment) {
        Remove-Item -LiteralPath ("Env:" + $key) -ErrorAction SilentlyContinue
        if ($OriginalEnvironment.ContainsKey($key)) { Set-Item -LiteralPath ("Env:" + $key) -Value $OriginalEnvironment[$key] }
    }
    Remove-Item -LiteralPath $Temp -Recurse -Force -ErrorAction SilentlyContinue
}
