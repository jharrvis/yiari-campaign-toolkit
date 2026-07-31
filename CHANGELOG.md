# Changelog

## Unreleased

- Updated WooCommerce campaign email contact address to media@yiari.or.id and added Paket B tracking plus order status links to campaign customer emails.
- Registered campaign post-payment statuses as WooCommerce paid statuses so paid campaign orders remain recognized as paid after certificate and shipping lifecycle transitions.
- Added campaign consent visibility in WooCommerce order admin and campaign CSV export, redirected the default shop archive to the campaign page, and added YIARI-styled single product presentation.
- Removed WooCommerce's per-option optional markers from the campaign donor reason radio choices while keeping the field optional.
- Expanded YIARI-styled WooCommerce surfaces across cart, checkout, order received, and my-account pages with consistent tables, forms, notices, navigation, buttons, and responsive layout treatment.
- Polished the campaign checkout information panel and aligned donor reason radio inputs with their labels, plus light WooCommerce form styling for a more YIARI-consistent checkout/cart experience.
- Updated the checkout campaign information section to Indonesian copy and replaced the donor reason textarea with radio choices plus a conditional custom reason field.
- Added automatic KiriminAja drop-off AWB initiation for paid Paket B/MIXED campaign orders, creating the KiriminAja shipping payment record without manual admin package input.
- Added safeguards for old KiriminAja transactions by recalculating them against the currently enabled courier before auto drop-off AWB creation, without changing the paid WooCommerce order total.
- Added KiriminAja pickup/payment metadata sync, one-hour retry throttling for failed AWB creation attempts, and public tracking fallback for campaign orders missing WooCommerce analytics rows.
- Added Midtrans payment reconciliation for pending WooCommerce campaign orders so successful payments can still trigger campaign statuses, certificates, and emails when the webhook is missed.
- Updated certificate PDF generation to use the client certificate template from `temp/certificate-template.html` with donor name and certificate number injected on the first page.
- Allowed WooCommerce checkout/order-pay pages through WooCommerce Store Coming Soon mode so public donors can complete Midtrans payment while other store pages may remain hidden.
- Isolated donation Midtrans Snap scripts from WooCommerce checkout/order-pay pages so WooCommerce Midtrans can load the matching sandbox or production Snap script without breaking donation pages.
- Added a Midtrans notification bridge so the existing donation AJAX notification URL can also update WooCommerce campaign orders.
- Allowed WooCommerce cart quantity inputs through the KiriminAja cart template sanitizer so donors can edit quantities on the cart page.
- Fixed campaign package buttons so selecting a package sets the chosen quantity as the final cart quantity instead of incrementing existing cart quantity on repeated clicks.
- Loaded campaign frontend assets on WooCommerce cart/checkout pages and added cart page-width plus visible editable quantity field styles.
- Rebuilt the campaign product shortcode from the `temp/product-card.html` and `temp/product-card.css` template, preserving WooCommerce checkout and quantity controls.
- Updated the campaign product shortcode with a client-reference two-column layout, quantity selectors, direct checkout forms, and clearer Paket A/B shipping copy.
- Added Sprint 5a Oxygen-friendly campaign frontend shortcodes for the Karmila & Gito landing section, two package cards, and cart icon.
- Added responsive public campaign frontend assets and AJAX cart count refresh for cached pages.
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
