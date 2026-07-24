# SKILL: Admin Dashboard Extensions

**Status:** Not started
**Sprint:** 4
**Depends on:** `order-status-engine`, `certificate-generation`, `shipping-sync` (reads their data)

## Purpose

Extend the existing WooCommerce Orders admin screen rather than building a
separate custom dashboard page — less code, familiar UI for whoever on YIARI's
side manages orders, and automatically inherits WooCommerce's existing
search/pagination/permissions infrastructure.

**Must be HPOS-compatible.** Check whether the site has High-Performance Order
Storage enabled (`Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()`)
and use the corresponding hook set — column hooks differ between the legacy
post-based orders screen and the HPOS orders screen. Confirm which is active on
the target site before implementation; do not assume one or the other.

## Features required (from brief §9)

- **Custom columns** on the Orders list: Paket (A/B), AWB/Resi number,
  Certificate status, Impact report status.
  - Legacy (post-based): `manage_edit-shop_order_columns` +
    `manage_shop_order_posts_custom_column`.
  - HPOS: `woocommerce_shop_order_list_table_columns` +
    `woocommerce_shop_order_list_table_custom_column`.
- **Filters**: dropdown for Paket A/B, and status filters (already partially
  covered by the custom order statuses from `order-status-engine`, which appear
  automatically in the default status filter dropdown — don't duplicate that
  UI, just add the Paket A/B filter).
- **Search**: extend `woocommerce_shop_order_search_fields` (or HPOS
  equivalent) so admin search matches certificate number and AWB number, not
  just order ID/customer name.
- **Internal notes column**: a simple textarea per order, stored as order meta
  `_internal_note`, editable inline or via a small modal — do not confuse this
  with WooCommerce's existing "Order notes" (customer-facing/system log) box;
  this is a separate, admin-only free-text field per brief's explicit ask.
- **Status history view**: read from the `ykt_order_status_log` table (from
  `order-status-engine`) and display as a simple table in the order edit
  screen's side panel (`woocommerce_admin_order_data_after_order_details` or
  a custom meta box).
- **Bulk action: "Kirim Ulang Email"**: register via
  `bulk_actions-edit-shop_order` (or HPOS equivalent), re-triggers the
  appropriate `WC_Email` from `email-engine` based on the order's *current*
  status — do not regenerate the certificate, only resend existing.
- **Export (CSV)**: a simple "Export" button/bulk action producing a CSV of
  filtered orders (columns: order ID, date, name, contact, package, quantity,
  payment status, certificate status, shipping status, AWB number, impact
  report status) — use `WP_Filesystem`/direct output with
  `Content-Type: text/csv` headers, no need for a full library given the modest
  column count; only pull in the `league/csv` composer package if quoting/escaping
  edge cases prove annoying with a hand-rolled implementation.

## Permissions

Confirm with human operator (see `PLAN.md` open question #3) whether all
dashboard users get full access or some need a restricted (view-only, no
export) role. Default assumption until confirmed: gate all these
features behind `manage_woocommerce` capability, same as the rest of the
Orders screen — do not invent a new capability unless the role question comes
back requiring one.

## Manual QA

- [ ] Confirm HPOS vs legacy status on target site before testing column hooks.
- [ ] All 4 custom columns show correct, up-to-date data for a mixed batch of
      Paket A/B sandbox orders in different lifecycle stages.
- [ ] Search by certificate number and by AWB number both return the correct
      order.
- [ ] Bulk "resend email" on a `wc-shipped` order resends the shipped email
      (not the payment-confirmation email).
- [ ] CSV export opens correctly in Excel/Google Sheets with no encoding
      /delimiter issues (test with a donor name containing a comma).
