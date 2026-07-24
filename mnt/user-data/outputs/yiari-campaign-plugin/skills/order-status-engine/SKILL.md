# SKILL: Custom Order Status Engine + Status Log

**Status:** Not started
**Sprint:** 1
**Depends on:** none (foundational — most other modules hook off these statuses)

## Purpose

Replace/extend WooCommerce's default statuses with the 7-stage lifecycle the
brief requires, and keep an auditable history of every transition (the brief
explicitly names "riwayat status" as a dashboard requirement, and names status
confusion in the prior campaign as a problem to avoid).

## Statuses to register

| Slug | Label (ID) | Applies to |
|---|---|---|
| `wc-pending-payment` | Menunggu Pembayaran | A + B |
| `wc-paid` | Dibayar | A + B |
| `wc-certificate-sent` | Sertifikat Terkirim | A + B |
| `wc-ready-to-ship` | Siap Dikirim | B only |
| `wc-shipped` | Sedang Dikirim | B only |
| `wc-delivered` | Diterima | B only |
| `wc-impact-report-sent` | Laporan Dampak Terkirim | A + B (A can reach this directly after certificate-sent) |

Note: WooCommerce status slugs must be ≤20 characters including the `wc-` prefix
— all of the above fit.

## Implementation

1. Register each via `register_post_status()` on `init`, with
   `exclude_from_search`, `show_in_admin_all_list`, `show_in_admin_status_list`
   all `true`, and a proper `label_count` (`_n_noop`).
2. Add all 7 to the `wc_order_statuses` filter, inserted in logical order
   (after `wc-pending` / before or replacing `wc-completed` — decide final
   placement so the admin Orders list dropdown reads top-to-bottom in lifecycle
   order).
3. **Do not remove WooCommerce's own core statuses** (`pending`, `on-hold`,
   `processing`, `completed`, `cancelled`, `refunded`, `failed`) — map Midtrans
   webhook outcomes to core statuses first (see below), then have this module's
   listener advance from `processing` into `wc-paid` and onward. This avoids
   fighting the Midtrans plugin's own status-management code.

   ```
   Midtrans settlement/capture → WooCommerce "processing" (Midtrans plugin's own behavior)
                                → (this module) → "paid" → ... → "impact-report-sent"
   Midtrans deny/expire/cancel → WooCommerce "failed"/"cancelled" (no further action)
   ```

## Status log (custom table)

Create on plugin activation via `dbDelta()`:

```sql
CREATE TABLE {$wpdb->prefix}ykt_order_status_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id BIGINT UNSIGNED NOT NULL,
  status_from VARCHAR(40) NULL,
  status_to VARCHAR(40) NOT NULL,
  changed_at DATETIME NOT NULL,
  actor VARCHAR(60) NOT NULL DEFAULT 'system',
  PRIMARY KEY (id),
  KEY order_id (order_id)
) {$charset_collate};
```

Hook `woocommerce_order_status_changed( $order_id, $from, $to, $order )` — insert
one row per transition via `$wpdb->insert()` with prepared values. `actor` is
`system` for automated transitions, or the current admin user's login for manual
ones (check `is_admin() && current_user_can(...)`).

## Manual QA

- [ ] All 7 statuses appear correctly labeled in Orders list filter dropdown, in
      lifecycle order.
- [ ] A sandbox Midtrans payment success correctly lands the order on `wc-paid`,
      not stuck on `processing`.
- [ ] Every transition (including ones triggered by other modules, e.g.
      shipping-sync) produces exactly one row in the log table — no duplicates,
      no missed transitions.
- [ ] Manually changing status from the admin UI records `actor` as the admin's
      username, not `system`.
