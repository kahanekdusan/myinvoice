[CmdletBinding()]
param(
    [string]$AgentDir = 'C:\docker\fakturace\deploy-agent',

    [switch]$CheckOnly
)

$ErrorActionPreference = 'Stop'

$repository = 'kahanekdusan/myinvoice'
$workflowFile = 'deploy-production.yml'
$workflowPath = ".github/workflows/$workflowFile"
$expectedBranch = 'production'
$expectedImage = 'ghcr.io/kahanekdusan/myinvoice'
$expectedDeployScriptSha256 = '3b4b74225a43a6d5c6f409112ea59777e12d11de12768bcb4fd7bf388586e6a6'
$apiBase = "https://api.github.com/repos/$repository"
$resolvedAgentDir = [System.IO.Path]::GetFullPath($AgentDir)
$expectedAgentDir = [System.IO.Path]::GetFullPath('C:\docker\fakturace\deploy-agent')

if ($resolvedAgentDir -ne $expectedAgentDir) {
    throw "Refusing unexpected deployment agent path: $resolvedAgentDir"
}

$logDir = Join-Path $resolvedAgentDir 'logs'
$stateFile = Join-Path $resolvedAgentDir 'state.json'
$deployScript = Join-Path $resolvedAgentDir 'deploy-production.ps1'
New-Item -ItemType Directory -Path $logDir -Force | Out-Null
$logFile = Join-Path $logDir 'watcher.log'

function Write-AgentLog {
    param([string]$Message)
    $line = '{0} {1}' -f (Get-Date).ToUniversalTime().ToString('o'), $Message
    Add-Content -LiteralPath $logFile -Value $line -Encoding utf8
    Write-Host $line
}

function Invoke-DockerChecked {
    param([string[]]$Arguments, [string]$Failure)
    & docker @Arguments
    if ($LASTEXITCODE -ne 0) { throw $Failure }
}

$mutex = [System.Threading.Mutex]::new($false, 'Global\MyInvoiceProductionWatcher')
$lockTaken = $false

try {
    try {
        $lockTaken = $mutex.WaitOne(0)
    } catch [System.Threading.AbandonedMutexException] {
        $lockTaken = $true
    }
    if (-not $lockTaken) {
        Write-AgentLog 'Another watcher instance is already running; skipping.'
        exit 0
    }

    if (-not (Test-Path -LiteralPath $deployScript)) {
        throw "Trusted local deployment script is missing: $deployScript"
    }
    $deployScriptSha256 = (Get-FileHash -LiteralPath $deployScript -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($deployScriptSha256 -ne $expectedDeployScriptSha256) {
        throw "Trusted local deployment script hash mismatch: $deployScriptSha256"
    }

    $headers = @{
        Accept = 'application/vnd.github+json'
        'X-GitHub-Api-Version' = '2022-11-28'
        'User-Agent' = 'myinvoice-production-watcher/1.0'
    }
    $runsUri = "$apiBase/actions/runs?branch=$expectedBranch&event=push&per_page=20"
    $runs = Invoke-RestMethod -Uri $runsUri -Headers $headers -Method Get
    $run = $runs.workflow_runs | Where-Object { $_.path -eq $workflowPath } | Select-Object -First 1
    if (-not $run) {
        Write-AgentLog 'No production workflow run exists yet.'
        exit 0
    }

    if ($run.path -ne $workflowPath -or $run.head_branch -ne $expectedBranch -or $run.event -ne 'push') {
        throw 'Latest workflow run does not match the trusted workflow, branch and event.'
    }
    if ($run.status -ne 'completed' -or $run.conclusion -ne 'success') {
        Write-AgentLog "Latest production run $($run.id) is $($run.status)/$($run.conclusion); deployment is blocked."
        exit 0
    }

    $sha = [string]$run.head_sha
    if ($sha -notmatch '^[0-9a-f]{40}$') {
        throw "Workflow returned an invalid commit SHA: $sha"
    }

    $branch = Invoke-RestMethod -Uri "$apiBase/branches/$expectedBranch" -Headers $headers -Method Get
    $branchSha = [string]$branch.commit.sha
    if ($branchSha -ne $sha) {
        Write-AgentLog "Successful run $($run.id) is not for current production HEAD; deployment is blocked."
        exit 0
    }

    if (Test-Path -LiteralPath $stateFile) {
        $state = Get-Content -LiteralPath $stateFile -Raw | ConvertFrom-Json
        if ($state.deployed_sha -eq $sha) {
            Write-AgentLog "Production SHA $sha is already deployed."
            exit 0
        }
    }

    if ($CheckOnly) {
        Write-AgentLog "Check-only: production SHA $sha is ready for deployment from run $($run.id)."
        exit 0
    }

    $tag = "$expectedImage`:sha-$sha"
    Write-AgentLog "Pulling trusted production image for SHA $sha."
    Invoke-DockerChecked -Arguments @('pull', $tag) -Failure "Docker pull failed for $tag."

    $inspectJson = & docker image inspect $tag
    if ($LASTEXITCODE -ne 0) { throw "Docker inspect failed for $tag." }
    $image = ($inspectJson | ConvertFrom-Json | Select-Object -First 1)
    $revision = [string]$image.Config.Labels.'org.opencontainers.image.revision'
    if ($revision -ne $sha) {
        throw "Image revision label '$revision' does not match trusted SHA '$sha'."
    }

    $digestRef = [string]($image.RepoDigests | Where-Object {
        $_ -match '^ghcr\.io/kahanekdusan/myinvoice@sha256:[0-9a-f]{64}$'
    } | Select-Object -First 1)
    if (-not $digestRef) {
        throw 'Pulled image does not expose an expected immutable GHCR digest.'
    }

    Write-AgentLog "Deploying verified digest $digestRef from run $($run.id)."
    & $deployScript -ImageRef $digestRef
    if ($LASTEXITCODE -ne 0) { throw 'Trusted production deployment script failed.' }

    $newState = [ordered]@{
        deployed_sha = $sha
        image_digest = $digestRef
        workflow_run_id = [long]$run.id
        workflow_run_url = [string]$run.html_url
        deployed_at_utc = (Get-Date).ToUniversalTime().ToString('o')
    }
    $temporaryState = "$stateFile.tmp"
    $newState | ConvertTo-Json | Set-Content -LiteralPath $temporaryState -Encoding utf8
    Move-Item -LiteralPath $temporaryState -Destination $stateFile -Force
    Write-AgentLog "Deployment state recorded for SHA $sha."
} catch {
    Write-AgentLog "ERROR: $($_.Exception.Message)"
    throw
} finally {
    if ($lockTaken) { $mutex.ReleaseMutex() }
    $mutex.Dispose()
}
