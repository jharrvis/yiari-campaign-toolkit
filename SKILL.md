# SKILL: Checkout Differentiation (Paket A vs Paket B)

**Status:** Implemented, pending sandbox QA
**Sprint:** 1
**Depends on:** WooCommerce products for Paket A and Paket B already created.

## Purpose

Paket A (donation-only) needs no shipping address. Paket B (buy-1-donate-1) needs
full address + shipping. Both need an optional engagement question and consent
checkboxes. This module makes the checkout form adapt based on what's in the cart.

## Data model

- Custom product meta `_campaign_package_type` = `A` | `B`, set via a custom field
  in the Product Data panel (General tab, simple text/select field — both products
  are Simple Products, matching KiriminAja plugin's current limitation of only
  supporting Simple Product type).
- Order meta (set at checkout, read later by other modules):
  - `_campaign_package_type` (copied from product at order-creation time — do not
    re-derive it later from line items, in case the product changes after order)
  - `_donor_reason` (free text, optional)
  - `_consent_updates` (bool)
  - `_consent_yiari_info` (bool)
  - `_consent_testimonial` (bool)

## Hooks to use

- `woocommerce_before_checkout_form` — enqueue a small JS that toggles visibility
  of the address block based on cart contents (for UX only; **never rely on JS
  alone for validation**).
- `woocommerce_checkout_fields` filter — conditionally mark billing/shipping
  address fields as required only when cart contains a Paket B product. Do this
  server-side so it's enforced even with JS disabled.
- `woocommerce_after_order_notes` — render the custom question + consent
  checkboxes.
- `woocommerce_checkout_process` — server-side validation: if cart has Paket B
  and address fields are empty, `wc_add_notice( ..., 'error' )`.
- `woocommerce_checkout_update_order_meta` — persist the custom meta listed above
  onto the order (use `$order->update_meta_data()` + `$order->save()`, HPOS-safe,
  not `update_post_meta()`).
- `woocommerce_checkout_create_order_line_item` — optionally copy
  `_campaign_package_type` from product to order at this point instead, if
  multiple products/mixed carts become a future possibility.

## Edge cases

- **Mixed cart** (Paket A + Paket B in same order): treat as Paket B for address
  requirement purposes (shipping is needed). Flag this case in `PLAN.md` if the
  business actually wants to block mixed carts entirely — brief doesn't say
  either way explicitly.
- **Quantity > 1** on Paket B: brief states "beli 3 paket = 3 buku fisik + 3 buku
  donasi" — this is just the product quantity, no special handling needed beyond
  correct qty read in Module 6 (progress counter).
- Do not assume `WC()->cart` is available in all hook contexts — check
  `did_action( 'woocommerce_cart_loaded_from_session' )` or use the `$cart` object
  passed to the filter/action where available.

## Manual QA

- [ ] Add Paket A only → checkout has no address fields, submits successfully.
- [ ] Add Paket B only → address fields required, checkout fails validation if
      empty even with JS disabled (test with browser dev tools "disable JS").
- [ ] Add both in one cart → address required (per edge-case decision above).
- [ ] Consent checkboxes and reason text persist correctly on the order (check
      via `wc_get_order( $id )->get_meta( '_donor_reason' )` in WP-CLI or admin).
