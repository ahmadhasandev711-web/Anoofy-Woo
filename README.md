# Anoofy for WooCommerce

Order operations for WooCommerce stores: call-center confirmation, preparation, delivery attempts,
COD settlement and returns. Arabic (RTL) and English. See [SPEC.md](SPEC.md) for scope and decisions.

Status: **Phase 1 built, not yet run on a real WooCommerce** — Orders screen (tabs, search, assignment), order box (call log, confirm/reject/cancel), audit log, Arabic translation. The pure core is unit-tested; the WordPress glue needs the spikes in SPEC section 6 (S-8..S-12).

## Try it locally (Docker only, no PHP needed)

```powershell
docker compose run --rm composer install      # once: creates vendor/
docker compose up -d                          # WordPress at http://localhost:28080
```

1. Open http://localhost:28080 and finish the WordPress setup (choose the language you want).
2. Plugins → Add New → install and activate **WooCommerce**.
3. Plugins → activate **Anoofy for WooCommerce**. A new **Anoofy** menu appears with a health check.

If port 28080 is taken or forbidden on Windows, set `ANOOFY_WP_PORT` in a `.env` file next to `docker-compose.yml`
(check `netsh interface ipv4 show excludedportrange protocol=tcp` for forbidden ranges).

## Tests

```powershell
docker compose run --rm composer test
```

## Install on a real site

Copy the folder to `wp-content/plugins/anoofy-woo`, run `composer install --no-dev` inside it, activate.
Requires PHP 8.1+, WordPress 7.0+, WooCommerce 11.2+.

## Layout

```
anoofy-woo.php        plugin header + bootstrap
src/Core/             pure PHP domain (no WordPress): roles, permission matrix, order state machine
src/Database/         table schema (dbDelta) + migrator
src/Admin/            admin screens
src/Capabilities.php  maps the matrix to WordPress roles/capabilities
tests/                PHPUnit tests
```
