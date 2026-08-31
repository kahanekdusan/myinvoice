# Canonical local-development lifecycle for Windows PowerShell 5.1 and PowerShell 7.
[CmdletBinding()]
param(
    [ValidateSet('init', 'config', 'up', 'down', 'status')]
    [string]$Action = 'up'
)

$ErrorActionPreference = 'Stop'
$ProjectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$RuntimeBase = Join-Path $ProjectRoot '.docker-local'
$RuntimeDir = Join-Path $RuntimeBase 'development'
$RuntimeEnv = Join-Path $RuntimeDir 'runtime.env'
$ComposeFile = Join-Path $ProjectRoot 'ops\docker\compose.local.yaml'
$ProtectedEnvKeys = @('APP_PORT', 'DB_PORT', 'DB_ROOT_PASSWORD', 'DB_PASSWORD', 'MYINVOICE_PEPPER', 'MYINVOICE_SECRET_KEY', 'LOCAL_GENERATION_ID', 'SKIP_FRONTEND_TYPECHECK', 'COMPOSE_PROJECT_NAME')

function New-SecureBytes([int]$Count) {
    $bytes = New-Object byte[] $Count
    $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
    return $bytes
}

function New-HexToken([int]$Count) {
    return -join ((New-SecureBytes $Count) | ForEach-Object { $_.ToString('x2') })
}

function New-Base64Token([int]$Count) {
    return [Convert]::ToBase64String((New-SecureBytes $Count))
}

function Assert-SafeRuntimePath {
    foreach ($path in @($RuntimeBase, $RuntimeDir, $RuntimeEnv)) {
        if ((Test-Path -LiteralPath $path) -and ((Get-Item -LiteralPath $path).Attributes -band [IO.FileAttributes]::ReparsePoint)) {
            throw 'Local runtime path must not be a symlink or reparse point.'
        }
    }
}

function Protect-RuntimeDirectory {
    Assert-SafeRuntimePath
    if (-not (Test-Path -LiteralPath $RuntimeBase -PathType Container) -or -not (Test-Path -LiteralPath $RuntimeDir -PathType Container)) {
        throw 'Local runtime directory is missing.'
    }
    if ($env:OS -eq 'Windows_NT') {
        $inheritance = [System.Security.AccessControl.InheritanceFlags]'ContainerInherit, ObjectInherit'
        $propagation = [System.Security.AccessControl.PropagationFlags]::None
        foreach ($target in @($RuntimeBase, $RuntimeDir)) {
            $acl = [System.Security.AccessControl.DirectorySecurity]::new()
            $acl.SetAccessRuleProtection($true, $false)
            foreach ($identity in @(
                [System.Security.Principal.WindowsIdentity]::GetCurrent().User,
                [System.Security.Principal.SecurityIdentifier]::new('S-1-5-18'),
                [System.Security.Principal.SecurityIdentifier]::new('S-1-5-32-544')
            )) {
                $rule = [System.Security.AccessControl.FileSystemAccessRule]::new($identity, 'FullControl', $inheritance, $propagation, 'Allow')
                [void]$acl.AddAccessRule($rule)
            }
            Set-Acl -LiteralPath $target -AclObject $acl
        }
    }
}

function Protect-RuntimeFile {
    Assert-SafeRuntimePath
    if (-not (Test-Path -LiteralPath $RuntimeEnv -PathType Leaf)) { throw 'Local runtime env is missing.' }
    if ($env:OS -eq 'Windows_NT') {
        $acl = [System.Security.AccessControl.FileSecurity]::new()
        $acl.SetAccessRuleProtection($true, $false)
        foreach ($identity in @(
            [System.Security.Principal.WindowsIdentity]::GetCurrent().User,
            [System.Security.Principal.SecurityIdentifier]::new('S-1-5-18'),
            [System.Security.Principal.SecurityIdentifier]::new('S-1-5-32-544')
        )) {
            $rule = [System.Security.AccessControl.FileSystemAccessRule]::new($identity, 'FullControl', 'Allow')
            [void]$acl.AddAccessRule($rule)
        }
        Set-Acl -LiteralPath $RuntimeEnv -AclObject $acl
    }
}

function Initialize-Runtime {
    Assert-SafeRuntimePath
    if (-not (Test-Path -LiteralPath $RuntimeBase)) {
        New-Item -ItemType Directory -Path $RuntimeBase | Out-Null
    }
    Assert-SafeRuntimePath
    if (-not (Test-Path -LiteralPath $RuntimeDir)) {
        New-Item -ItemType Directory -Path $RuntimeDir | Out-Null
    }
    Assert-SafeRuntimePath
    Protect-RuntimeDirectory
    if (-not (Test-Path -LiteralPath $RuntimeEnv)) {
        $temp = Join-Path $RuntimeDir ('runtime.env.' + [Guid]::NewGuid().ToString('N') + '.tmp')
        $lines = @(
            'APP_PORT=8090',
            'DB_PORT=3311',
            ('DB_ROOT_PASSWORD=' + (New-HexToken 24)),
            ('DB_PASSWORD=' + (New-HexToken 24)),
            ('MYINVOICE_PEPPER=' + (New-Base64Token 32)),
            ('MYINVOICE_SECRET_KEY=' + (New-Base64Token 32)),
            ('LOCAL_GENERATION_ID=' + (New-HexToken 16))
        )
        [IO.File]::WriteAllLines($temp, $lines, (New-Object Text.UTF8Encoding($false)))
        Assert-SafeRuntimePath
        Move-Item -LiteralPath $temp -Destination $RuntimeEnv
    }
    Assert-SafeRuntimePath
    Protect-RuntimeFile
}

function Read-Runtime {
    $values = @{}
    $allowed = @('APP_PORT', 'DB_PORT', 'DB_ROOT_PASSWORD', 'DB_PASSWORD', 'MYINVOICE_PEPPER', 'MYINVOICE_SECRET_KEY', 'LOCAL_GENERATION_ID')
    foreach ($line in [IO.File]::ReadAllLines($RuntimeEnv)) {
        if ($line -notmatch '^([A-Z_][A-Z0-9_]*)=(.*)$') { throw "Invalid runtime env line." }
        $key = $Matches[1]
        if ($key -notin $allowed) { throw "Unknown runtime key $key." }
        if ($values.ContainsKey($key)) { throw "Runtime key $key occurs more than once." }
        $values[$key] = $Matches[2]
    }
    return $values
}

function Test-Runtime([hashtable]$Values) {
    foreach ($key in @('APP_PORT', 'DB_PORT', 'DB_ROOT_PASSWORD', 'DB_PASSWORD', 'MYINVOICE_PEPPER', 'MYINVOICE_SECRET_KEY', 'LOCAL_GENERATION_ID')) {
        if (-not $Values.ContainsKey($key) -or [string]::IsNullOrWhiteSpace([string]$Values[$key])) {
            throw "Runtime key $key is missing or empty."
        }
    }
    foreach ($key in @('APP_PORT', 'DB_PORT')) {
        $text = [string]$Values[$key]
        if ($text -notmatch '^[0-9]+$') { throw "$key must be an integer." }
        $port = [int]$text
        if ($port -lt 1024 -or $port -gt 65535) { throw "$key must be between 1024 and 65535." }
    }
    if ([int]$Values.APP_PORT -eq [int]$Values.DB_PORT) { throw 'APP_PORT and DB_PORT must differ.' }
    if ([string]$Values.LOCAL_GENERATION_ID -notmatch '^[0-9a-f]{32}$') {
        throw 'LOCAL_GENERATION_ID must be 32 lowercase hexadecimal characters.'
    }
}

function Invoke-CleanDocker([string[]]$Arguments) {
    $saved = @{}
    try {
        foreach ($key in $ProtectedEnvKeys) {
            $item = Get-Item -LiteralPath ("Env:" + $key) -ErrorAction SilentlyContinue
            if ($null -ne $item) { $saved[$key] = $item.Value }
            Remove-Item -LiteralPath ("Env:" + $key) -ErrorAction SilentlyContinue
        }
        $output = @(& docker @Arguments)
        if ($LASTEXITCODE -ne 0) { throw "Docker command failed: $($Arguments -join ' ')" }
        return $output
    } finally {
        foreach ($key in $ProtectedEnvKeys) {
            Remove-Item -LiteralPath ("Env:" + $key) -ErrorAction SilentlyContinue
            if ($saved.ContainsKey($key)) { Set-Item -LiteralPath ("Env:" + $key) -Value $saved[$key] }
        }
    }
}

function Assert-LocalDocker {
    if (-not [string]::IsNullOrWhiteSpace($env:DOCKER_HOST) -or -not [string]::IsNullOrWhiteSpace($env:DOCKER_CONTEXT)) {
        throw 'DOCKER_HOST/DOCKER_CONTEXT must be unset for local development.'
    }
    $context = ((Invoke-CleanDocker -Arguments @('context', 'show')) | Out-String).Trim()
    if ([string]::IsNullOrWhiteSpace($context)) { throw 'Cannot determine current Docker context.' }
    $endpoint = ((Invoke-CleanDocker -Arguments @('context', 'inspect', $context, '--format', '{{ (index .Endpoints "docker").Host }}')) | Out-String).Trim()
    if ($endpoint -notmatch '^(unix|npipe)://') { throw "Refusing non-local Docker endpoint $endpoint." }
}

function Invoke-Compose([string[]]$Arguments) {
    Invoke-CleanDocker -Arguments ($ComposeArgs + $Arguments)
}

function Test-VolumeIdentity([hashtable]$Values) {
    foreach ($suffix in @('app-data', 'db-data')) {
        $volume = 'myinvoice_dev_' + [string]$Values.LOCAL_GENERATION_ID + '_' + $suffix
        Invoke-CleanDocker -Arguments @('volume', 'create', '--label', 'io.dusankahanek.myinvoice.scope=local-development', '--label', ("io.dusankahanek.myinvoice.generation=" + [string]$Values.LOCAL_GENERATION_ID), $volume) | Out-Null
        $actual = ((Invoke-CleanDocker -Arguments @('volume', 'inspect', '--format', '{{ index .Labels "io.dusankahanek.myinvoice.generation" }}', $volume)) | Out-String).Trim()
        $scope = ((Invoke-CleanDocker -Arguments @('volume', 'inspect', '--format', '{{ index .Labels "io.dusankahanek.myinvoice.scope" }}', $volume)) | Out-String).Trim()
        if ($scope -ne 'local-development' -or $actual -ne [string]$Values.LOCAL_GENERATION_ID) {
            throw "Refusing existing untrusted volume $volume."
        }
    }
}

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) { throw 'docker is required.' }
Assert-LocalDocker
Assert-SafeRuntimePath
if ($Action -in @('init', 'config', 'up')) {
    Initialize-Runtime
} elseif (-not (Test-Path -LiteralPath $RuntimeEnv -PathType Leaf)) {
    throw 'Local stack is not initialized; nothing was changed.'
} else {
    Assert-SafeRuntimePath
    Protect-RuntimeDirectory
    Protect-RuntimeFile
}
$Runtime = Read-Runtime
Test-Runtime $Runtime
$ComposeArgs = @('compose', '--project-name', 'myinvoice_dev', '--env-file', $RuntimeEnv, '-f', $ComposeFile)
Invoke-Compose -Arguments @('version')
Invoke-Compose -Arguments @('config', '--quiet')

switch ($Action) {
    { $_ -eq 'init' -or $_ -eq 'config' } {
        Write-Output ("LOCAL_DEV_PREFLIGHT=GO;URL=http://localhost:{0}" -f $Runtime.APP_PORT)
        break
    }
    'up' {
        Test-VolumeIdentity $Runtime
        Invoke-Compose -Arguments @('up', '-d', '--build', '--wait', 'app', 'db')
        Write-Output ("LOCAL_DEV_UP=PASS;URL=http://localhost:{0}" -f $Runtime.APP_PORT)
    }
    'down' {
        Invoke-Compose -Arguments @('down')
        Write-Output 'LOCAL_DEV_DOWN=PASS;VOLUMES=PRESERVED'
    }
    'status' {
        Invoke-Compose -Arguments @('ps')
    }
}
