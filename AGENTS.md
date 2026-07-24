# AGENTS.md — YIARI Campaign Toolkit Plugin

Instructions for any AI coding agent (Codex CLI, etc.) working in this repository.
Read this file fully before writing any code. Read `PLAN.md` next, then the relevant
file under `skills/<module>/SKILL.md` before starting work on that module.

## 1. What this project is

A **custom WordPress/WooCommerce plugin** built for YIARI (Yayasan IAR Indonesia) to
run a book-donation fundraising campaign ("Petualangan Karmila & Gito, Menyelamatkan
Orangutan"). It does **not** replace WooCommerce, Midtrans, or KiriminAja — it is a
thin orchestration layer that listens to their hooks/events and adds the
campaign-specific logic they don't provide out of the box.

Stack already decided and NOT to be changed by the agent:
- **WordPress + WooCommerce** — product catalog, cart, checkout, orders
- **Midtrans-WooCommerce** (official plugin) — payment gateway, sandbox + production
- **KiriminAja WooCommerce plugin** — shipping rates, AWB/resi generation, tracking
- This repo — a single custom plugin, working name `yiari-campaign-toolkit`

Do not suggest or implement a different CMS, framework, or headless architecture.
Do not fork or modify the Midtrans or KiriminAja plugin files directly — only hook
into their public actions/filters. If a required hook does not exist in the
installed version of those plugins, stop and flag it in your output rather than
monkey-patching their code.

## 2. Repository layout convention

```
yiari-campaign-toolkit/
├── yiari-campaign-toolkit.php      # main plugin file, header, activation/deactivation
├── includes/
│   ├── class-ykt-checkout.php      # Module 1
│   ├── class-ykt-order-status.php  # Module 2
│   ├── class-ykt-certificate.php   # Module 3
│   ├── class-ykt-emails.php        # Module 4
│   ├── class-ykt-shipping-sync.php # Module 5
│   ├── class-ykt-progress.php      # Module 6
│   ├── class-ykt-admin.php         # Module 7
│   └── class-ykt-segmentation.php  # Module 8
├── templates/
│   ├── certificate.php             # HTML template rendered to PDF
│   └── emails/                     # WC_Email template overrides
├── assets/
│   ├── admin.css / admin.js
│   └── certificate-fonts/ (if needed)
├── vendor/                         # composer deps (dompdf etc.) — commit or .gitignore per client's choice
├── PLAN.md
├── AGENTS.md
└── skills/
```

One class per module, one hook registration point per class (constructor or an
`init()` method called from the main plugin file). Do not scatter `add_action`
calls across multiple files for the same module.

## 3. Coding conventions

- Follow **WordPress Coding Standards (WPCS)** for PHP — tabs for indentation,
  Yoda conditions optional (don't fight the client's existing style if a WPCS
  config is added later), but always:
  - Prefix everything with `ykt_` (functions) or `YKT_` (classes/constants) to
    avoid collisions with other plugins on the client's multi-plugin site.
  - Escape all output (`esc_html`, `esc_attr`, `esc_url`) and sanitize all input
    (`sanitize_text_field`, nonces on every form/action).
  - Use `$wpdb->prepare()` for any custom SQL — no raw string concatenation.
- **HPOS (High-Performance Order Storage) compatible.** Use
  `wc_get_order( $order_id )` / `$order->get_meta()` / `$order->update_meta_data()`
  — never touch `$_POST`/postmeta tables directly, and never assume
  `get_post_meta()` works on orders.
- All new order statuses must be registered through `register_post_status()` +
  the `wc_order_statuses` filter — see `skills/order-status-engine/SKILL.md`.
- No inline styles/scripts beyond trivial cases; enqueue via
  `wp_enqueue_style` / `wp_enqueue_script` with proper handles and versioning.
- Composer for PHP dependencies (e.g. `dompdf/dompdf`). Do not vendor-copy
  library code manually into `includes/`.
- Every module must degrade gracefully if WooCommerce, Midtrans plugin, or
  KiriminAja plugin is inactive — check with `class_exists()` /
  `function_exists()` before hooking, and show an admin notice instead of a
  fatal error.

## 4. Security & credentials

- Never hardcode API keys (Midtrans, KiriminAja) in this plugin's code. They
  are configured in their respective plugin settings screens — this plugin
  only reads order/shipping data that's already available via WooCommerce
  APIs, it never calls Midtrans or KiriminAja APIs directly unless a specific
  module (see `shipping-sync`) explicitly requires it, in which case use the
  credentials already stored by those plugins, never duplicate storage.
- Certificate PDFs and any personal data exports must be stored outside the
  publicly-served `uploads` path if possible, or protected via a signed/expiring
  URL — donor PII (name, address, phone) must not be guessable via sequential
  URLs.

## 5. How to work through this repo

1. Read `PLAN.md` for the sprint breakdown and current status.
2. Before starting a module, read its `skills/<module>/SKILL.md` — it has the
   hook names, data schema, and edge cases specific to that module.
3. Implement one module at a time, in the sprint order given in `PLAN.md`,
   unless told otherwise by the human operator.
4. After implementing a module, update the "Status" line at the top of its
   `SKILL.md` and the corresponding row in `PLAN.md`'s sprint table.
5. Do not start Module 5 (shipping-sync) work that assumes a specific
   KiriminAja hook exists without first checking the installed plugin's source
   for that hook (see `skills/shipping-sync/SKILL.md` §"Verify before building").

## 6. Definition of done (per module)

A module is done when:
- It works with **WooCommerce order statuses actually changing** in a live
  sandbox test (Midtrans sandbox payment → status change observed), not just
  unit-tested in isolation.
- It fails safely (no fatal errors, no silent data loss) if a dependency
  plugin is missing/updated/changes behavior.
- Manual QA steps listed in the module's `SKILL.md` have been run once and
  the results noted (pass/fail) in `PLAN.md`.

## 7. What NOT to do

- Do not introduce a second database/queue system (Redis, external DB) for
  this plugin — WordPress DB + transients are sufficient at this scale.
- Do not build a REST API layer beyond what's needed for the progress-counter
  shortcode (Module 6) unless a later requirement explicitly asks for one.
- Do not remove or rename the 7 order statuses defined in
  `skills/order-status-engine/SKILL.md` without flagging it — the client
  (YIARI) uses these status names in their own reporting language.
- Do not silently swap dompdf for another PDF library, or WC_Email for
  `wp_mail()` — these choices were made deliberately (see PLAN.md rationale).
