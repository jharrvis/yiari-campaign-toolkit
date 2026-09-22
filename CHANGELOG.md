# Changelog

## Unreleased

- Limited campaign attachments to the “Mari Berpetualang bersama Karmila dan Gito!” email; order-confirmation emails are sent without the book or certificate.

- Hardened campaign book delivery with per-order locking, fail-closed personalized attachments, pre-send generation, and queued email retries so retries happen first and the original source PDF is used only as a logged last-resort fallback.

- Made concurrent email-triggered book generation safe so one process cannot delete another process’s completed personalized PDF.

- Added a committed parser-compatible helper PDF so donor personalization works in web PHP environments where `proc_open` is disabled, without final PDF optimization.

- Set the campaign email sender name to `Donasi Buku YIARI` without changing non-campaign WooCommerce email sender names.

- Switched donor book personalization to the fixed PDF source, preserved the `Hai,` donor greeting while removing the background block and final Ghostscript compression, and added hourly cleanup for personalized PDFs older than one day. A temporary parser-compatible copy is created only when generating the donor PDF.

- Removed the generic compressed-book fallback from donor emails so a personalization failure cannot send a PDF with the `[ Nama Donatur ]` placeholder.

- Localized campaign customer emails and built-in WooCommerce order emails to Bahasa Indonesia while preserving the paid-email subject “Mari Berpetualang bersama Karmila dan Gito!”.

- Ensured WooCommerce payment/customer email routes replace the generic campaign book PDF with the donor-personalized version on page 3.

- Corrected the donor-name overlay on page 3 to replace the actual `[ Nama Donatur ]` placeholder position and preserve the `Hai,` greeting.

- Added per-donor PDF book personalization using the `[ Nama Donatur ]` placeholder on page 3, with optimized output and a safe generic-book fallback.

- Lowered the floating campaign package selector by 50px on desktop and mobile.

- Raised the floating campaign package selector above Oxygen's Back to Top button on desktop and mobile.

- Added a responsive floating campaign package selector that links directly to Paket A and Paket B product cards.

- Changed paid campaign email sender to `donasi@yiari.or.id` and restored the non-personalized compressed digital book attachment.

- Updated the paid donor notification email with Indonesian Karmila & Gito campaign copy, conditional Paket B tracking, and the digital book PDF attachment.

- Prevented province UI synchronization from triggering duplicate checkout updates that could suppress shipping and insurance fees.

- Prevented repeated checkout reloads by triggering province changes only when the value actually changes.

- Synchronized the selected province field immediately when changing KiriminAja districts without requiring a page refresh.

- Hardened checkout address autofill against WooCommerce fragment re-renders and classic/Blocks field ID variants.

- Re-applied selected KiriminAja province, city, and postcode after WooCommerce checkout fragments refresh.

- Prevented address autofill from triggering parallel checkout updates that could reuse stale shipping rates when changing KiriminAja districts.

- Added checkout autofill for city, province, and postcode from the selected KiriminAja district on Paket B/MIXED orders.

- Expanded the new campaign character-image lift to tablet widths and enforced it against Oxygen positioning overrides.
- Raised the two mobile campaign character images so they no longer cover the hero copy on the new campaign page.
- Removed the empty viewport-height space below the new campaign hero so the next section follows the existing hero content cleanly.
- Fixed responsive flow before the campaign product section by removing smaller-screen negative offsets and allowing stacked Oxygen sections to size to their content.
- Fixed the mobile campaign hero banner sizing so the top background image spans the viewport instead of rendering as a narrow 80px strip.
- Added a KiriminAja payment fallback sync that checks remote QRIS pickup invoices during shipping polling when webhook callbacks are delayed or missed.
- Added a WooCommerce > Campaign Report admin page with date-package-status filters, campaign summary metrics, donor/order table, and Excel-friendly CSV export.
- Added the [ykt_book_counter target="1000"] shortcode for Indonesian campaign book totals with AJAX refresh support.
- Preserved Oxygen Builder query-string requests by bypassing canonical and shop redirects for builder edit or iframe URLs.
- Fixed the empty cart drawer action button so it no longer stretches vertically across the drawer panel.
- Standardized campaign plugin button styling to YIARI light blue and reduced the empty cart drawer action button size.
- Updated the [ykt_cart_icon] header shortcode to render a white icon-only cart trigger with conditional item badge and an AJAX right-side cart drawer.
- Added the [ykt_single_product_campaign] Oxygen shortcode for campaign product detail pages with package-specific copy, quantity checkout form, and responsive YIARI styling.
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
