# Anoofy for WooCommerce — Spec v0.1

Status: draft for the owner's review. Nothing below is final until marked **Approved**.
Verified 2026-10-28: WooCommerce 11.2.1 (tested up to WordPress 7.1.3, requires WordPress 7.0, PHP 7.4+);
WooCommerce 11.6 (planned Feb 2027) will require PHP 8.1+. WordPress 7.2 is in beta.

## 1. Purpose

WooCommerce already handles orders, stock, refunds, roles and the COD gateway. It does **not** handle the
operational workflow Egyptian COD stores run every day. This plugin adds exactly that layer, inside the
store's own WordPress, with no external service:

| Area | WooCommerce gives you | This plugin adds |
|---|---|---|
| Confirmation | order statuses, notes | call-center workflow: assignment, call log, confirm/reject, confirmation rate |
| Preparation | processing status | warehouse step, per-line prepared check, cancel-while-preparing with restock confirmation |
| Delivery | tracking via extensions | courier + tracking, delivery attempts (max 3), failed/retry/return-to-origin |
| COD money | COD gateway (no reconciliation) | settlement from the courier's statement marks orders paid in one batch |
| Returns/RTO | refunds | inspection (restock / damaged / pending per item), approval, one stock movement per item |
| Control | admin/shop-manager roles | 5 operational roles, audit log, KPI dashboard |

Out of scope for the MVP: outbound courier APIs, WhatsApp/SMS, multi-store dashboards, subscriptions.

## 2. Decisions

| ID | Decision | Why |
|---|---|---|
| D-W1 | The operational status (`NEW … RETURNED_TO_ORIGIN`) is **separate** from the WooCommerce status. The plugin maps the two at defined points (section 3). | Woo keeps its own lifecycle and third-party extensions keep working. |
| D-W2 | Own tables, orders referenced **by ID only**; orders are read/written through WooCommerce CRUD (`wc_get_order`, `$order->save()`), never raw SQL. Declared HPOS-compatible. | Works with both HPOS and legacy posts storage. |
| D-W3 | Stock is never adjusted by delta for cancellations. Woo reduces stock for `processing`/`on-hold` and restores it when the order is `cancelled`; the plugin only decides **when** to set `cancelled`. Cancelling while preparing waits for the warehouse's "restocked" confirmation first. | Avoids double restock; uses the platform's own mechanism. |
| D-W4 | Four WP roles (`anoofy_order_manager`, `_warehouse`, `_shipping`, `_customer_service`) + administrators/shop managers act as Owner. Capabilities are `anoofy_*`, generated from the permission matrix (code, not DB). Ops staff use the plugin's own Orders screen and do not need WooCommerce capabilities. | Least privilege; warehouse staff never see customer contact data. |
| D-W5 | COD orders stay unpaid in Woo until courier settlement, then are marked paid in one batch. | Matches reality: money arrives with the courier statement. (Mechanism to verify, see spike S-3.) |
| D-W6 | State transitions are atomic: `UPDATE … SET ops_status = :to WHERE order_id = :id AND ops_status = :from`; zero rows updated = someone else moved it first. | Two agents cannot both confirm the same order. |
| D-W7 | Background work uses Action Scheduler (bundled with WooCommerce), idempotent handlers. Never raw WP-Cron. | WP-Cron is unreliable on cheap hosting. |
| D-W8 | Bilingual from day one: English source strings, Arabic translation shipped, RTL handled by WordPress. Every user-facing string uses text domain `anoofy-woo`. | Most users are Egyptian; Arabic is required. |
| D-W9 | Minimum versions: PHP 8.1, WordPress 7.0, WooCommerce 11.2. CI matrix PHP 8.1–8.4. | Aligned with WooCommerce's own PHP 8.1 move. |
| D-W10 | The domain core (`src/Core`) is plain PHP with no WordPress calls and is fully unit-tested. WordPress/Woo code is a thin layer on top. | A future central dashboard or REST consumer reuses the same rules. |
| D-W11 | Admin UI: server-rendered WordPress admin (list table + order meta box) for the MVP. | Fastest, most compatible; revisit React/DataViews only if needed. |
| D-W12 | License headers: GPL-2.0-or-later (WordPress ecosystem expectation). Revenue comes from licenses/updates/support. **Owner to decide** the licensing/update channel (e.g. Freemius or own server). | Open question. |

## 3. Operational status to WooCommerce status (to confirm in spikes)

| Operational status | Woo status | Notes |
|---|---|---|
| NEW, UNDER_REVIEW | `on-hold` | Stock is reduced for on-hold; the customer is not told "processing" before the call. |
| CONFIRMED, PREPARING, READY_TO_SHIP | `processing` | |
| SHIPPED, DELIVERY_FAILED | `processing` | tracking/courier stored in our table; optional custom status later |
| DELIVERED | `completed` | payment still unpaid until settlement (COD) |
| REJECTED, CANCELLED | `cancelled` | Woo restores stock |
| RETURNED_TO_ORIGIN | open question | restock happens per item after inspection; avoid Woo's automatic full restock (S-4) |

Settings (planned): require confirmation for COD only / all orders; max delivery attempts (default 3);
suppress or customise the Woo customer emails for ops transitions.

## 4. Data model (v1, implemented in `Schema.php`)

- `anoofy_order_ops` — one row per order: ops status, assignee, facts the state machine needs, courier, tracking.
- `anoofy_call_attempts` — who called, outcome, note, time.
- `anoofy_audit_log` — append-only: who, what, from → to, reason.

Later phases add: delivery attempts, COD settlements (+ orders), returns (+ items), stock movements.

## 5. Phases

| Phase | Content | Status |
|---|---|---|
| 0 | Skeleton, HPOS declaration, roles/capabilities, schema, pure core (state machine + permissions) with tests, dev Docker. Next: CI, WordPress/Woo stubs + PHPStan, integration tests on real WooCommerce (wp-env or this compose file). | **Core done** |
| 1 | Confirmation: hook new orders into ops, Orders screen (status tabs with counts, search, assignment), order meta box (call log, confirm/reject/cancel, assign), audit log, Arabic translation. | **Built, not yet run on WooCommerce** (see section 6, S-8..S-12) |
| 2 | Preparation + shipping (courier, tracking). | |
| 3 | Delivery attempts, failed/retry/return-to-origin. | |
| 4 | COD settlement: import the courier statement (CSV), match by tracking number, mark paid in one batch. | |
| 5 | Returns / RTO with inspection. | |
| 6 | KPIs (confirmation rate, delivery success, COD outstanding, per-agent); optional courier adapters (Bosta, Aramex, Mylerz…) behind a `CourierGateway` interface. | |

## 6. Spikes (verify on a real WooCommerce 11.2.1 before building the dependent phase)

- **S-1** Where a new COD order lands (`processing` vs `on-hold`) and the cleanest hook to move it to `on-hold` without sending the customer the wrong email.
- **S-2** Which Woo customer emails fire on each status change; how to suppress/override them per transition.
- **S-3** Marking a COD order paid at settlement: `payment_complete()` vs setting `date_paid`, and its effect on stock and emails.
- **S-4** RTO restock: Woo's cancel restores the full reduced quantity; per-item restock after inspection (damaged items) needs `wc_update_product_stock` or a zero-amount refund with restock. Confirm which works.
- **S-5** Editing an order's items/address after confirmation: stock and totals recalculation behaviour.
- **S-6** A user with only `anoofy_*` capabilities: can the plugin read orders (`wc_get_orders`) and show the minimum customer data the role is allowed to see.
- **S-7** Performance of the Orders screen with 50k orders (indexes on `anoofy_order_ops`).

## 7. Testing

- Unit tests (PHPUnit 10.5, runs on PHP 8.1–8.4): permission matrix (one action = one test), state machine, schema, environment checks.
- Integration tests against real WordPress + WooCommerce (no mocks of WooCommerce) from Phase 1.
- Every transition has a test for allowed, forbidden role, wrong state, and each guard.
- **S-8** `woocommerce_new_order` + `woocommerce_order_status_changed` as the tracking hooks: block checkout creates a `checkout-draft` order first; confirm a real COD order gets exactly one `anoofy_order_ops` row, and that orders created as `pending` (manual admin orders) get one when they change status. Untracked statuses today: checkout-draft, auto-draft, trash, pending, failed.
- **S-9** Orders screen search: `wc_get_orders(['s' => ...])` must match name, phone, email and order number in both HPOS and legacy storage.
- **S-10** Meta box on the HPOS order screen: `add_meta_box` with `wc_get_page_screen_id('shop-order')`, and `assets/js/order-box.js` posting to `admin-post.php` (the box cannot contain its own form: WooCommerce's order form already wraps it and its hidden `action` field would win).
- **S-11** Rejecting/cancelling calls `$order->update_status('cancelled')`: confirm WooCommerce restores the reduced stock and that our own `woocommerce_order_status_cancelled` handler ignores it (re-entrancy guard).
- **S-12** Existing orders from before the plugin: they get an ops row (status NEW) the first time they are opened. Decide whether Phase 2 needs a one-off import of open orders.

## 8. Phase 1 design assumptions (change here first)

- Any call attempt moves NEW to UNDER_REVIEW; only an *answered* call allows Confirm.
- Reject and Cancel always cancel the WooCommerce order (WooCommerce restores stock itself).
- The WooCommerce status is **not** mirrored from the ops status unless the `anoofy_sync_woo_status` option or filter is true (default off), because status changes can send customer emails (spike S-2).
- Customer phone and city are shown only with `anoofy_order_view_customer_contact` (warehouse staff do not have it).
- Translations: English source, Arabic shipped in `languages/` (`tools/i18n.py` regenerates `.pot`/`.po`/`.mo`; a unit test fails if a string has no Arabic).
