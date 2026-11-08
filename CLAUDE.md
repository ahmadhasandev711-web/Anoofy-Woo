# Anoofy for WooCommerce — working rules

WooCommerce plugin: call-center confirmation, preparation, delivery, COD settlement, returns. Read `SPEC.md` first;
if code and spec disagree, stop and say so.

## Communication
- The owner writes Egyptian Arabic: answer in Egyptian Arabic, direct and technical. Windows/PowerShell commands.

## Versions (verified 2026-10-28; re-verify before changing)
PHP >= 8.1 (CI 8.1-8.4) · WordPress >= 7.0 · WooCommerce >= 11.2 (11.2.1 current) · PHPUnit 10.5 (runs on 8.1).
Do not use syntax newer than PHP 8.1 in `src/` (no `readonly class`, no typed class constants, no `#[\Override]`).

## Rules
- `src/Core` is pure PHP: no WordPress/WooCommerce functions, no globals, no I/O. Everything there has a unit test.
- State changes go through `OrderStateMachine::transition()`; persist with a compare-and-set update (`WHERE ops_status = :from`).
- Orders: WooCommerce CRUD only (`wc_get_order`, `wc_get_orders`, `$order->save()`); never raw SQL on order tables (HPOS).
- Own SQL: always `$wpdb->prepare()`; table names via the prefix helper in `Schema`.
- Every request handler: check capability (`Action::capability()`), verify a nonce, sanitise input, escape output.
- Every user-facing string: `__()`/`_x()` with text domain `anoofy-woo` (literal strings only). English source, Arabic translation shipped: add the Arabic to `tools/ar.json`, then run `python3 tools/i18n.py` (needs `pip install polib`) and commit `languages/`.
- Admin screens/boxes: use `Admin\Labels` for text, `Services\OrderOpsService` for every change (never the repository directly), `ActionsController` + nonce `anoofy_order_<id>` for every write.
- Jobs: Action Scheduler, idempotent. Never `wp_schedule_event` for business logic.
- Add a permission to the matrix first (with a test), then use it.
- Before every commit: `docker compose run --rm composer test` must pass.
