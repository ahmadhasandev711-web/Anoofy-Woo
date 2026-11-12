# tools/run-e2e-env.ps1
# Sets up and validates the local E2E Docker network between Anoofy-Woo and EngazPRO.

$ErrorActionPreference = 'Stop'

Write-Host "`n=== Anoofy-Woo: E2E Dev Environment Runner ===" -ForegroundColor Cyan

# 1. Verify Docker Compose version >= 2.24
Write-Host "[1/5] Checking Docker Compose version..." -ForegroundColor Yellow
$composeVerStr = (docker compose version) 2>&1 | Out-String
if ($composeVerStr -match '(\d+)\.(\d+)\.(\d+)') {
    $major = [int]$matches[1]
    $minor = [int]$matches[2]
    if ($major -lt 2 -or ($major -eq 2 -and $minor -lt 24)) {
        Write-Host "  [FAIL] Docker Compose version $major.$minor is too old. Requires >= 2.24 for 'ports: !override'." -ForegroundColor Red
        exit 1
    }
    Write-Host "  [PASS] Docker Compose version is $major.$minor (>= 2.24)." -ForegroundColor Green
} else {
    Write-Host "  [WARN] Could not parse Docker Compose version string: $composeVerStr" -ForegroundColor Yellow
}

# 2. Check Windows hosts file for required domains
Write-Host "[2/5] Checking Windows hosts file..." -ForegroundColor Yellow
$hostsPath = "$env:SystemRoot\System32\drivers\etc\hosts"
if (-not (Test-Path $hostsPath)) {
    Write-Host "  [FAIL] Hosts file not found at: $hostsPath" -ForegroundColor Red
    exit 1
}

$hostsContent = Get-Content $hostsPath -Raw
$hasAnoofy = $hostsContent -match 'anoofy-wp\.test'
$hasEngaz = $hostsContent -match 'engazpro\.test'

if (-not $hasAnoofy -or -not $hasEngaz) {
    Write-Host "  [FAIL] Missing required host mappings in $hostsPath" -ForegroundColor Red
    Write-Host "`nPlease add the following lines to your Windows hosts file as Administrator:" -ForegroundColor Yellow
    if (-not $hasAnoofy) {
        Write-Host "  127.0.0.1 anoofy-wp.test" -ForegroundColor Cyan
    }
    if (-not $hasEngaz) {
        Write-Host "  127.0.0.1 engazpro.test" -ForegroundColor Cyan
    }
    exit 1
}
Write-Host "  [PASS] Both anoofy-wp.test and engazpro.test mapped in hosts file." -ForegroundColor Green

# 3. Ensure external Docker network exists
Write-Host "[3/5] Ensuring Docker network 'engaz-e2e' exists..." -ForegroundColor Yellow
$netExists = docker network ls --filter "name=^engaz-e2e$" -q
if (-not $netExists) {
    Write-Host "  Creating network 'engaz-e2e'..." -ForegroundColor Yellow
    docker network create engaz-e2e | Out-Null
    if ($LASTEXITCODE -ne 0) {
        Write-Host "  [FAIL] Failed to create network 'engaz-e2e'." -ForegroundColor Red
        exit 1
    }
    Write-Host "  [PASS] Created network 'engaz-e2e'." -ForegroundColor Green
} else {
    Write-Host "  [PASS] Network 'engaz-e2e' already exists." -ForegroundColor Green
}

# 4. Start containers with E2E override
Write-Host "[4/5] Starting containers with E2E override..." -ForegroundColor Yellow
docker compose -f docker-compose.yml -f docker-compose.e2e.override.yml up -d
if ($LASTEXITCODE -ne 0) {
    Write-Host "  [FAIL] Failed to start containers." -ForegroundColor Red
    exit 1
}
Write-Host "  [PASS] Containers are running with E2E override." -ForegroundColor Green

# 5. Verify HTTP connectivity
Write-Host "[5/5] Verifying network connectivity..." -ForegroundColor Yellow

# A. From host (retry up to 15 times to allow Apache to finish starting)
$hostOk = $false
$hostLine = ''
for ($i = 1; $i -le 15; $i++) {
    $hostHead = curl.exe -sI http://anoofy-wp.test:28080 2>&1 | Out-String
    $hostLine = ($hostHead -split "`r?`n" | Where-Object { $_ -match '^HTTP/' }) | Select-Object -First 1
    if ($hostLine -and ($hostLine -match '200|301|302')) {
        $hostOk = $true
        break
    }
    Start-Sleep -Seconds 2
}

if ($hostOk) {
    Write-Host "  [PASS] Host -> http://anoofy-wp.test:28080 ($($hostLine.Trim()))" -ForegroundColor Green
} else {
    Write-Host "  [FAIL] Host -> http://anoofy-wp.test:28080 ($hostLine)" -ForegroundColor Red
    exit 1
}

# B. From inside WordPress container
$containerHead = (docker compose exec -T wordpress curl -sI http://anoofy-wp.test:28080) 2>&1 | Out-String
$containerLine = ($containerHead -split "`r?`n" | Where-Object { $_ -match '^HTTP/' }) | Select-Object -First 1
if ($containerLine -and ($containerLine -match '200|301|302')) {
    Write-Host "  [PASS] WordPress container -> http://anoofy-wp.test:28080 ($($containerLine.Trim()))" -ForegroundColor Green
} else {
    Write-Host "  [FAIL] WordPress container -> http://anoofy-wp.test:28080 ($containerLine)" -ForegroundColor Red
    exit 1
}

# C. Check engazpro.test resolution inside WordPress container
$prevEAP = $ErrorActionPreference
$ErrorActionPreference = 'Continue'
$engazRes = (docker compose exec -T wordpress getent hosts engazpro.test) 2>&1 | Out-String
$resExit = $LASTEXITCODE
$ErrorActionPreference = $prevEAP

if ($resExit -eq 0 -and $engazRes.Trim() -ne '') {
    Write-Host "  [PASS] WordPress container -> resolved engazpro.test: $($engazRes.Trim())" -ForegroundColor Green
} else {
    Write-Host "  [WARN] WordPress container -> engazpro.test is not resolvable yet (EngazPRO container is not running on engaz-e2e network yet)." -ForegroundColor Yellow
}

Write-Host "`n=== E2E Environment is READY! ===" -ForegroundColor Green
exit 0
