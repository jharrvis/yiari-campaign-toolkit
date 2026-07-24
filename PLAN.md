# PLAN.md — YIARI Campaign Toolkit: Development Plan

Source brief: `Konsep Fundraising Web Buku Karmila & Gito.docx` (YIARI, book donation
campaign "Petualangan Karmila & Gito, Menyelamatkan Orangutan").

Decided stack (see `AGENTS.md` §1 — do not change): WordPress + WooCommerce +
Midtrans-WooCommerce (payment) + KiriminAja WooCommerce plugin (shipping) + this
custom plugin (`yiari-campaign-toolkit`) for everything else.

## Campaign business rules (reference)

- **Paket A** — "Traktir Buku" (Rp100.000/pcs): donor funds book(s) for children near
  orangutan habitat. No physical book to the donor, no shipping/address needed.
- **Paket B** — "Beli 1, Traktir 1" (Rp150.000 / 2 pcs): donor gets 1 physical book +
  funds 1 donated book. Requires shipping address, generates AWB via KiriminAja,
  quantity scales (buy N packages = N books shipped + N books donated).

## Order status lifecycle (both packages, Paket A skips shipping stages)

```
pending-payment → paid → certificate-sent
                              │
                    (Paket A) │ (Paket B)
                              ▼
                          ready-to-ship → shipped → delivered → impact-report-sent
```

## Sprint plan

| Sprint | Scope | Modules | Depends on |
|---|---|---|---|
| **Sprint 0** | Environment setup: WooCommerce + Midtrans (sandbox) + KiriminAja (sandbox) connected and verified with a manual test order | — (no custom plugin code yet) | Sandbox credentials for Midtrans & KiriminAja |
| **Sprint 1** | Checkout differentiation + custom order status engine | `checkout-differentiation`, `order-status-engine` | Sprint 0 done |
| **Sprint 2** | Certificate generation + automated email engine | `certificate-generation`, `email-engine` | Sprint 1 (needs order status hooks) |
| **Sprint 3** | Shipping status sync + public progress counter | `shipping-sync`, `progress-counter` | Sprint 1; KiriminAja hook verified (see that skill file) |
| **Sprint 4** | Admin dashboard extensions + donor segmentation | `admin-dashboard`, `segmentation-reporting` | Sprints 1–3 (reads their data) |
| **Sprint 5** | End-to-end sandbox QA: full journey Paket A and Paket B, failure-path testing | all | Sprints 1–4 |
| **Sprint 6** | Go-live: swap to production credentials (Midtrans + KiriminAja), 1-week monitoring | — | Sprint 5 sign-off |
| **Sprint 7 (later phase, not in initial scope)** | WhatsApp notification automation | new module, not yet speced | Sprint 6 stable |

Update the **Status** column below as work progresses. Do not mark a sprint done
until its module(s)' "Definition of done" checklist (see `AGENTS.md` §6) passes.

| Sprint | Status | Notes |
|---|---|---|
| 0 | Not started | |
| 1 | Not started | |
| 2 | Not started | |
| 3 | Not started | |
| 4 | Not started | |
| 5 | Not started | |
| 6 | Not started | |

## Open questions to confirm with human operator / YIARI before or during the relevant sprint

These come directly from the brief's own "Hal yang Perlu Diputuskan" section — do
not guess at answers, surface them instead:

1. Does the installed KiriminAja plugin version expose an action hook when an AWB/
   status changes, or does `shipping-sync` need to poll their API instead? →
   resolve at start of Sprint 3, see `skills/shipping-sync/SKILL.md`.
2. Threshold (quantity or Rupiah amount) that defines a "Donatur Besar" segment →
   needed for Sprint 4, `segmentation-reporting`.
3. Who gets admin dashboard access, and do they need role-restricted views (e.g.
   can only see, not export) → needed for Sprint 4, `admin-dashboard`.
4. Certificate visual design / branding assets (YIARI logo file, font, layout) →
   needed before Sprint 2 can produce a real (non-placeholder) certificate.
5. Exact email copy for each of the 4 automated emails (confirmation, shipped,
   delivered, impact report) — the brief gives an example message but not final
   copy for all four → needed for Sprint 2.
6. WhatsApp provider (Fonnte / Qontak / Wablas / other) for the later phase →
   not blocking for Sprints 0–6, only for Sprint 7.

## Module → Sprint → Skill file map

| Module | Sprint | Skill file |
|---|---|---|
| Checkout differentiation (Paket A/B fields) | 1 | `skills/checkout-differentiation/SKILL.md` |
| Custom order status engine + status log | 1 | `skills/order-status-engine/SKILL.md` |
| Certificate PDF generation | 2 | `skills/certificate-generation/SKILL.md` |
| Automated email engine | 2 | `skills/email-engine/SKILL.md` |
| Shipping status sync (KiriminAja) | 3 | `skills/shipping-sync/SKILL.md` |
| Public progress counter | 3 | `skills/progress-counter/SKILL.md` |
| Admin dashboard extensions | 4 | `skills/admin-dashboard/SKILL.md` |
| Donor segmentation & impact reporting | 4 | `skills/segmentation-reporting/SKILL.md` |

## Manual QA checklist for Sprint 5 (full end-to-end)

Run each of these against the **sandbox** stack before touching production credentials:

- [ ] Paket A: checkout → Midtrans sandbox payment success → status auto-advances
      to `paid` → certificate PDF generated + emailed → status `certificate-sent`
      → no shipping stages triggered → progress counter increments by correct qty.
- [ ] Paket B, qty = 1: same as above, plus → `ready-to-ship` → KiriminAja AWB
      created → status `shipped` → shipped email w/ tracking link sent.
- [ ] Paket B, qty = 3: verify 3 physical books + 3 donated books reflected
      correctly in order meta and progress counter (not counted as 1 unit).
- [ ] Payment failure/expire in Midtrans sandbox → order stays in
      `pending-payment` / moves to `Failed` → no certificate, no email, no AWB
      triggered.
- [ ] Checkout attempted while KiriminAja rate API is slow/unavailable → order
      does not silently lose the shipping option (see AGENTS.md fail-safe rule).
- [ ] Admin dashboard: filter by Paket A/B, search by certificate number and by
      AWB number, resend-email bulk action, CSV export all work.
- [ ] Progress counter shortcode reflects only `paid`-or-later orders, updates
      within the cache TTL, and does not error under concurrent test orders.

## Non-goals for this phase (explicitly out of scope)

- WhatsApp automation (Sprint 7, later phase).
- Multi-campaign / multi-tenant architecture — this plugin is scoped to this one
  campaign, though written cleanly enough to be forked for a future YIARI
  campaign (do not over-engineer generality beyond that).
- Any change to WooCommerce, Midtrans plugin, or KiriminAja plugin core files.
