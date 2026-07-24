# Changelog

## 0.1.0 - 2026-07-24

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
