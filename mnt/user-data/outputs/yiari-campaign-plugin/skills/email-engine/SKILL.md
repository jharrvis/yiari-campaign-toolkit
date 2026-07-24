# SKILL: Automated Email Engine

**Status:** Not started
**Sprint:** 2
**Depends on:** `order-status-engine`, `certificate-generation` (for the first email's attachment)

## Purpose

Send the 4 automated donor-facing emails required by the brief, using
WooCommerce's own `WC_Email` framework (not raw `wp_mail()`) so delivery is
logged consistently with every other WooCommerce transactional email and
inherits the site's existing email styling/header/footer.

## The 4 emails

| # | Trigger (order status → ) | Content |
|---|---|---|
| 1 | `paid` | Thank-you + package details + contribution amount + transaction code + shipping info (Paket B only) + note that a certificate/report will follow |
| 2 | `shipped` | Courier name, AWB/resi number, tracking link |
| 3 | `delivered` | Thank-you + social share invite + story template + hashtag |
| 4 | `impact-report-sent` | Books printed count, distribution locations, beneficiary count, curated documentation |

Exact copy for all 4 is an **open question** — see `PLAN.md` open questions list.
Build with placeholder copy that's easy for a non-developer to edit later
(store subject/body as translatable strings via `__()`, or as an editable
WooCommerce email template file — do not hardcode copy the client can't touch
without a developer).

## Implementation

1. Create one `WC_Email` subclass per email (extend `WC_Email`), registered via
   the `woocommerce_email_classes` filter — this gives each one its own
   enable/disable toggle and recipient field in **WooCommerce > Settings >
   Emails** automatically, which the client (or future developer) can manage
   without touching code.
2. Trigger each from the corresponding `woocommerce_order_status_changed`
   transition (`to === 'paid'`, `'shipped'`, `'delivered'`,
   `'impact-report-sent'`), calling `$this->email->trigger( $order_id )` style,
   consistent with how core WooCommerce emails are wired.
3. Email #1 must attach the certificate PDF — read path from order meta
   `_certificate_path` (set by `certificate-generation` module); if it's not
   yet present (race condition/hook order issue), log a warning via
   `wc_get_logger()` and send without the attachment rather than failing
   silently or fatal-erroring.
4. Templates live in `templates/emails/` following WooCommerce's own
   `plain`/`html` subfolder convention so theme overrides work the normal
   WooCommerce way (`yourtheme/woocommerce/emails/*.php`).

## Personalization requirements (from brief §6.A / open question #5)

Each email must reflect: donor name, package type (A/B), quantity, and (for
Paket B) shipping details. Pull these from the order meta set in
`checkout-differentiation` (`_campaign_package_type`) and standard WooCommerce
order data (billing name, line item quantities) — do not re-collect this data
separately.

## Manual QA

- [ ] All 4 emails appear as separate toggles in WooCommerce > Settings >
      Emails and can be individually disabled without breaking others.
- [ ] Email #1 arrives with PDF attached for both Paket A and Paket B orders.
- [ ] Email #2 only fires for Paket B orders (Paket A never reaches
      `wc-ready-to-ship`/`wc-shipped`).
- [ ] Resending via the admin dashboard's bulk action (see `admin-dashboard`
      skill) re-sends the correct email for the order's *current* status, not
      always email #1.
- [ ] Test with a donor name containing an ampersand or quote character —
      verify it doesn't break HTML rendering or email headers.
