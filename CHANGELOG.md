# Changelog

## Unreleased

- Fixed Paket B checkout validation to follow the active KiriminAja district field instead of requiring WooCommerce city/postcode fields that are not present on the live checkout.
- Fixed Paket B checkout field schema so billing address, city, postcode, country, and KiriminAja district fields are marked required when present.
- Added Sprint 4 WooCommerce admin order extensions with campaign columns, Paket filtering, certificate/AWB search fields, internal notes, status history, resend-email bulk action, and campaign CSV export.
- Added donor segmentation for Paket A/B, filterable big-donor total and quantity thresholds, manual Mitra tagging, and an admin impact update broadcast flow.
- Updated Sprint 4 planning and module skill statuses to implemented pending sandbox QA.

## 0.1.0 - 2026-07-24

- Added Sprint 4 WooCommerce order admin columns for package, AWB/resi, certificate, impact status, and internal notes.
- Added Paket A/B/MIXED order filters, campaign CSV export, and search support for certificate number, AWB, internal notes, and donor segment meta.
- Added inline admin-only internal note saving plus an order edit screen field.
- Added campaign status history display from the `ykt_order_status_log` table on order edit screens.
- Added admin bulk action to resend the email that matches each selected order's current campaign status.
- Added donor segmentation for Paket A, Paket B, filterable Donatur Besar threshold, and manual Mitra tagging.
- Added WooCommerce submenu for segment-based impact update broadcast, updating matching orders to `impact-sent`.
- Added dynamic impact broadcast message support in the impact email template flow.

- Added Sprint 3 KiriminAja shipping synchronization using the installed plugin transaction table because no AWB/status-specific public hook is exposed.
- Added 15-minute WP-Cron polling plus immediate sync on relevant order status changes for Paket B/mixed campaign orders.
- Added lifecycle mapping from KiriminAja AWB/status data to campaign `ready-to-ship`, `shipped`, and `delivered` statuses.
- Added HPOS-compatible order meta for `_shipping_awb_number`, `_shipping_courier_name`, `_kiriminaja_order_id`, and `_kiriminaja_status`.
- Added a guard so KiriminAja setting WooCommerce `completed` after delivery does not move an in-shipping campaign order backward to campaign `paid`.
- Added `[campaign_progress target="X"]` shortcode with cached books-funded and donor totals.
- Added AJAX refresh for campaign progress so the counter can remain fresh when page cache is enabled.
- Added courier/AWB display to campaign customer email templates when shipping data exists.

- Added Sprint 2 certificate generation for paid campaign orders using Dompdf.
- Added local Composer dependency manifest for `dompdf/dompdf`; `vendor/` remains ignored and can be installed per environment with Composer.
- Added certificate numbering with the `YIARI-KG-{YYYY}-{sequence}` format and guarded generation so each order keeps one certificate.
- Added secure certificate storage under WordPress uploads with non-guessable filenames and order meta references.
- Added a printable certificate template with donor name, package type, quantity, date, order number, and certificate number.
- Added WooCommerce customer email classes for campaign `paid`, `shipped`, `delivered`, and `impact-sent` lifecycle events.
- Added certificate PDF attachment support for the paid confirmation email.
- Added reusable HTML and plain-text campaign email templates with placeholder campaign copy.
- Kept Sprint 2 logic scoped to the campaign toolkit so KiriminAja, Midtrans, and WooCommerce core behavior remain untouched.

- Removed internal agent/planning files from Git tracking and ignored them locally so GitHub contains only plugin runtime/source files.

- Added the initial WordPress plugin bootstrap for YIARI Campaign Toolkit.
- Added WooCommerce dependency handling with an admin notice when WooCommerce is inactive.
- Added campaign order statuses and the `ykt_order_status_log` transition history table.
- Added automatic transition from WooCommerce paid gateway statuses to campaign `paid` for campaign orders only.
- Added product-level campaign package metadata for Paket A and Paket B.
- Added checkout differentiation so Paket A skips shipping while Paket B keeps shipping/address requirements.
- Added a checkout UX helper that hides address rows for Paket A after server-side requirements are relaxed.
- Added donor reason and consent fields, persisted to HPOS-compatible WooCommerce order meta.
- Updated Sprint 1 planning status to implemented pending sandbox QA.
- Shortened the impact report status slug to `wc-impact-sent` to stay within WordPress/WooCommerce 20-character status storage limits.
