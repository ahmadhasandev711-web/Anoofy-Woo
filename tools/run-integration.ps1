param(
    [ValidateSet('all', 'on', 'off')]
    [string]$Mode = 'all'
)

$modes = if ($Mode -eq 'all') { @('on', 'off') } else { @($Mode) }

$tests = @(
    @{ Name = 'S-1 (COD Order)'; File = 'test-s1-cod.php' },
    @{ Name = 'S-8 (Tracking Hooks)'; File = 'test-s8-tracking.php' },
    @{ Name = 'S-9 (Search)'; File = 'test-s9-search.php' },
    @{ Name = 'S-11 (Reject & Cancel & Stock)'; File = 'test-s11-cancel.php' },
    @{ Name = 'S-12 (Legacy Order Auto-Track)'; File = 'test-s12-legacy-order.php' }
)

$results = @{}

foreach ($hpos in $modes) {
    Write-Host "`n========================================================" -ForegroundColor Cyan
    Write-Host "   RUNNING INTEGRATION TEST SUITE (HPOS: $hpos)" -ForegroundColor Cyan
    Write-Host "========================================================`n" -ForegroundColor Cyan

    # 1. Bootstrap environment for this mode
    & "$PSScriptRoot\bootstrap-wp.ps1" -Hpos $hpos
    if ($LASTEXITCODE -ne 0) {
        Write-Error "Bootstrap failed for HPOS: $hpos"
        exit 1
    }

    $results[$hpos] = @{}

    # 2. Run each test
    foreach ($test in $tests) {
        Write-Host "`n>>> Running $($test.Name) [$($test.File)] (HPOS: $hpos) <<<" -ForegroundColor Yellow

        $output = @()
        $exitCode = 0

        if ($test.File -eq 'test-s12-legacy-order.php') {
            # Exact required sequence: deactivate -> create order -> activate -> test
            docker compose run --rm wpcli wp plugin deactivate anoofy-woo *> $null
            $createOut = docker compose run --rm wpcli wp eval-file wp-content/plugins/anoofy-woo/tools/create-legacy-order.php
            $legacyId = 0
            foreach ($line in $createOut) {
                if ($line -match '^\s*([0-9]+)\s*$') {
                    $legacyId = [int]$matches[1]
                }
            }
            docker compose run --rm wpcli wp plugin activate anoofy-woo *> $null

            $output = docker compose run --rm wpcli wp eval-file "wp-content/plugins/anoofy-woo/tests/Integration/$($test.File)" $legacyId
            $exitCode = $LASTEXITCODE
        } else {
            $output = docker compose run --rm wpcli wp eval-file "wp-content/plugins/anoofy-woo/tests/Integration/$($test.File)"
            $exitCode = $LASTEXITCODE
        }

        # Print test output
        foreach ($line in $output) {
            if ($line -match '^\s*\[PASS\]') {
                Write-Host $line -ForegroundColor Green
            } elseif ($line -match '^\s*\[FAIL\]') {
                Write-Host $line -ForegroundColor Red
            } else {
                Write-Host $line
            }
        }

        $hasFail = ($output -match '^\s*\[FAIL\]').Count -gt 0
        $passed = ($exitCode -eq 0 -and -not $hasFail)
        $results[$hpos][$test.Name] = if ($passed) { 'PASS' } else { 'FAIL (Finding)' }
    }
}

Write-Host "`n========================================================" -ForegroundColor Cyan
Write-Host "                INTEGRATION TEST SUMMARY                " -ForegroundColor Cyan
Write-Host "========================================================" -ForegroundColor Cyan

foreach ($hpos in $modes) {
    Write-Host "`n--- HPOS: $hpos ---" -ForegroundColor Yellow
    foreach ($test in $tests) {
        $status = $results[$hpos][$test.Name]
        $color = if ($status -eq 'PASS') { 'Green' } else { 'Red' }
        Write-Host ("  {0,-35} : {1}" -f $test.Name, $status) -ForegroundColor $color
    }
}
Write-Host "`n========================================================`n" -ForegroundColor Cyan

$allPassed = $true
foreach ($hpos in $modes) {
    foreach ($test in $tests) {
        if ($results[$hpos][$test.Name] -ne 'PASS') {
            $allPassed = $false
        }
    }
}

if (-not $allPassed) {
    exit 1
}

exit 0
