# SKILL: Public Progress Counter

**Status:** Not started
**Sprint:** 3
**Depends on:** `order-status-engine` (needs the `wc-paid`-or-later status set)

## Purpose

Campaign page shows live totals (books funded, donor count, progress toward
target). The brief explicitly warns that public enthusiasm can spike very fast
(reference: the earlier "Program Adopsi Kukang" campaign) — this must not
become a slow, uncached database query hit on every page load during a traffic
spike.

## Shortcode

`[campaign_progress target="X"]` — renders books-funded count, donor count, and
a progress bar/percentage toward `target`. Register via `add_shortcode()`.

## Query logic

Count **quantity of donated books**, not just order count:

- For Paket A orders: full line-item quantity counts as donated books.
- For Paket B orders: only the *donated half* counts toward the "books for
  children" total (1 donated book per package purchased, per brief's "Beli 1,
  Traktir 1" — do not double it with the donor's own physical copy).
- Only count orders whose status is `wc-paid` or any status **after** it in the
  lifecycle (i.e. exclude `wc-pending-payment` and any failed/cancelled order).
  Use an explicit whitelist array of statuses, not a "not in {pending, failed}"
  blacklist — safer if new statuses get added later.

Donor count = distinct count of orders (or distinct billing email) matching the
same status whitelist.

## Caching

- Use `get_transient()` / `set_transient()` with a short TTL (e.g. 2–5 minutes)
  — recompute is a simple aggregate SQL query, cheap enough to run every few
  minutes but not on every single page view during a spike.
- Invalidate/refresh the transient explicitly on the `woocommerce_order_status_changed`
  hook (when transitioning into `wc-paid`) rather than waiting for TTL expiry,
  so the counter still feels "live" to visitors without querying on every load.
- If the site later sits behind a full-page cache (varnish/Cloudflare page
  cache), the shortcode output should be loaded via a small AJAX/fetch call
  instead of being baked into the cached HTML, so the number doesn't go stale
  for hours. Flag this to the human operator if full-page caching is in use —
  don't silently assume it isn't.

## Manual QA

- [ ] Counter reflects correct totals immediately after a sandbox order moves
      to `wc-paid` (within the invalidation logic above, not waiting for full
      TTL).
- [ ] Concurrent test orders (simulate 5–10 rapid sandbox payments) don't cause
      duplicate counting or race conditions in the transient update.
- [ ] Paket B quantity math verified: buying 3 Paket B packages adds 3 to the
      donated-books counter, not 6.
- [ ] Cancelled/failed sandbox orders are correctly excluded from the count.
