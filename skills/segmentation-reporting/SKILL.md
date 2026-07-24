# SKILL: Donor Segmentation & Impact Reporting

**Status:** Not started
**Sprint:** 4
**Depends on:** `order-status-engine`, `admin-dashboard` (shares its screen for the send-broadcast UI)

## Purpose

Tag donors into segments (Paket A / Paket B / Donatur Besar / Mitra) and let an
admin send a semi-automated "Impact Update" broadcast to a chosen segment —
per the brief this does **not** need to be fully automatic/real-time, just
faster than the fully-manual process used in the prior campaign.

## Segmentation

- Store segment as order meta `_donor_segment`, computed automatically at the
  `wc-paid` transition:
  - `paket_a` / `paket_b` based on `_campaign_package_type`.
  - `donatur_besar` if quantity or order total exceeds a threshold — **value
    not yet confirmed, see `PLAN.md` open question #2**. Build the threshold as
    a filterable constant/option (`ykt_get_big_donor_threshold()` returning a
    filterable value), not a hardcoded number, so it can be adjusted without a
    code deploy once confirmed.
  - `mitra` (partner) — likely a manually-assigned tag rather than
    auto-computed (partners probably aren't identifiable purely from order
    data) — implement as a manual toggle in the admin order screen, not an
    automatic rule, unless told otherwise.
- A donor can belong to more than one segment conceptually (e.g. Paket B +
  Donatur Besar) — store as a comma-separated list or serialized array in the
  meta field, not a single value.

## Impact update broadcast

- Simple admin screen/section (can live inside `admin-dashboard`'s screen or as
  its own submenu page under WooCommerce): select segment(s) → compose/select
  an "Impact Update" content block (books printed count, distribution
  locations, beneficiary count, documentation) → send.
- Reuses the email #4 (`impact-report-sent`) template/class from `email-engine`
  — this module supplies the recipient list (by segment) and the dynamic
  content fields, not a new email system.
- Sending should update each recipient order's status to
  `wc-impact-report-sent` (so the status log and dashboard reflect it, and so
  the same donor doesn't get double-emailed by an accidental second broadcast
  — check current status before including an order in the send list).
- No need for real-time/queued sending at this campaign's expected scale — a
  synchronous loop with a reasonable batch size (e.g. process 50 at a time via
  WP-Cron if the list is large) is sufficient. Do not introduce a job queue
  library for this.

## Manual QA

- [ ] Sandbox orders auto-tag correctly into `paket_a`/`paket_b` and
      `donatur_besar` (once threshold is confirmed and configured) on reaching
      `wc-paid`.
- [ ] Manually tagging an order as `mitra` persists and displays correctly
      alongside auto-computed segments.
- [ ] Sending an impact update to a segment updates only those orders' status
      to `wc-impact-report-sent`, and does not re-send to an order already at
      that status.
- [ ] Segment filter in the broadcast UI matches the same underlying data the
      admin dashboard's Paket A/B filter uses (no drift between the two).
