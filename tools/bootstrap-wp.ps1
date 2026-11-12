param(
    [ValidateSet('on', 'off')]
    [string]$Hpos = 'on'
)

$ErrorActionPreference = 'Stop'

Write-Host "=== Anoofy-Woo: Bootstrap WordPress & WooCommerce (HPOS: $Hpos) ===" -ForegroundColor Cyan

# 1. Check if WordPress core is installed
Write-Host "[1/5] Checking WordPress core installation..." -ForegroundColor Yellow
$prevEAP = $ErrorActionPreference
$ErrorActionPreference = 'Continue'
docker compose run --rm wpcli wp core is-installed *> $null
$installed = ($LASTEXITCODE -eq 0)

if (-not $installed) {
    Write-Host "Installing WordPress core..." -ForegroundColor Green
    docker compose run --rm wpcli wp core install `
        --url="http://localhost:28080" `
        --title="Anoofy Store" `
        --admin_user="admin" `
        --admin_password="password123" `
        --admin_email="admin@example.com" `
        --skip-email
    docker compose run --rm wpcli wp config set WP_ENVIRONMENT_TYPE local --type=constant *> $null
} else {
    Write-Host "WordPress core is already installed." -ForegroundColor Green
    docker compose run --rm wpcli wp config set WP_ENVIRONMENT_TYPE local --type=constant *> $null
}

# 2. Install & Activate WooCommerce 11.2.1
Write-Host "[2/5] Checking WooCommerce 11.2.1..." -ForegroundColor Yellow
docker compose run --rm wpcli wp plugin is-installed woocommerce *> $null
$wcInstalled = ($LASTEXITCODE -eq 0)
$ErrorActionPreference = $prevEAP

if (-not $wcInstalled) {
    Write-Host "Installing WooCommerce 11.2.1..." -ForegroundColor Green
    docker compose run --rm wpcli wp plugin install woocommerce --version=11.2.1 --activate
} else {
    Write-Host "WooCommerce is installed. Ensuring activated..." -ForegroundColor Green
    docker compose run --rm wpcli wp plugin activate woocommerce
}

# 3. Activate anoofy-woo
Write-Host "[3/5] Activating anoofy-woo plugin..." -ForegroundColor Yellow
docker compose run --rm wpcli wp plugin activate anoofy-woo

# 4. Setup simple product & COD gateway
Write-Host "[4/5] Setting up product and COD gateway..." -ForegroundColor Yellow
docker compose run --rm wpcli wp eval-file wp-content/plugins/anoofy-woo/tools/setup-store.php $Hpos

# 5. Configure HPOS via WooCommerce CLI
Write-Host "[5/5] Configuring HPOS ($Hpos)..." -ForegroundColor Yellow
if ($Hpos -eq 'on') {
    docker compose run --rm wpcli wp wc hpos enable
} else {
    docker compose run --rm wpcli wp wc hpos disable
}

Write-Host "=== Bootstrap completed successfully! ===" -ForegroundColor Green
