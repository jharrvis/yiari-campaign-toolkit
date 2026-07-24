# SKILL: Shipping Status Sync (KiriminAja)

**Status:** Not started
**Sprint:** 3
**Depends on:** `order-status-engine`. Paket B orders only.

## Purpose

The KiriminAja WooCommerce plugin already creates the AWB/resi automatically
once an order reaches the right WooCommerce status. This module's only job is
to **observe** that AWB creation and subsequent delivery-status changes, and
translate them into this project's own status lifecycle
(`wc-ready-to-ship` → `wc-shipped` → `wc-delivered`), which then triggers
Module 4's emails.

## Verify before building (do this first, before writing any code)

This is the one module where the plan cannot fully specify hook names in
advance, because it depends on the exact version of the KiriminAja WooCommerce
plugin installed on the client's site. Before implementing:

1. Inspect the installed KiriminAja plugin's PHP source
   (`wp-content/plugins/kiriminaja-*/`) for any `do_action()` calls fired when:
   - an AWB/resi number is created for an order, and
   - a tracking/delivery status update is received (if the plugin does polling
     or has its own webhook receiver for courier status).
2. If such hooks exist, hook into them directly — this is the fast path.
3. If no hooks exist, check whether the plugin stores the resi number and
   delivery status in order meta (inspect via `wc_get_order( $id )->get_meta_data()`
   on a real KiriminAja-processed test order) — if so, use
   `woocommerce_updated_order_meta` or a scheduled WP-Cron job (every 15–30 min)
   that scans Paket B orders in `wc-ready-to-ship`/`wc-shipped` and checks
   whether the relevant meta key changed.
4. If neither exists, the fallback is polling KiriminAja's own tracking API
   directly using the API key already configured in their plugin's settings
   (do not create a second, duplicate API key entry for this) — only build this
   as a last resort, and note the added complexity/maintenance cost in
   `PLAN.md`.

**Do not guess a hook name and ship code that silently no-ops if it's wrong** —
confirm one of the three paths above actually fires in a sandbox test order
before writing the rest of this module.

## Status mapping

| KiriminAja / WooCommerce signal | This plugin's status |
|---|---|
| Order confirmed, shipping option chosen, awaiting AWB | `wc-ready-to-ship` |
| AWB/resi created | `wc-shipped` (fires Module 4 email #2 with courier + resi + tracking link) |
| Courier reports "delivered" (if KiriminAja surfaces this; some couriers don't push final delivery confirmation reliably) | `wc-delivered` (fires Module 4 email #3) |

If KiriminAja does not reliably surface a final "delivered" event for all
couriers used, add a manual "Mark as Delivered" action in the admin dashboard
(see `admin-dashboard` skill) as a fallback — do not leave orders permanently
stuck in `wc-shipped` with no way to progress them.

## Data to store

- `_shipping_awb_number` (order meta) — read by both this module and
  `admin-dashboard` for the AWB column.
- `_shipping_courier_name` — for the email #2 content.

## Manual QA

- [ ] Confirm in sandbox: which of the 3 hook/detection paths above actually
      applies to the installed plugin version, and document it here once known.
- [ ] AWB creation in KiriminAja sandbox correctly advances order to
      `wc-shipped` and triggers the shipped email within a reasonable delay
      (immediate if hook-based, within one cron cycle if polling-based).
- [ ] An order stuck without a final delivery signal can still be manually
      advanced by an admin without breaking the status log.
