# SKILL: Certificate Digital Otomatis (PDF)

**Status:** Not started
**Sprint:** 2
**Depends on:** `order-status-engine` (triggers on transition into `wc-paid`)

## Purpose

Generate and deliver a personalized PDF certificate automatically once payment
is confirmed — the brief notes some donors in the previous campaign didn't even
know they'd receive one, so both the generation *and* clear upfront messaging
about it matter (messaging is a copy/content task, not this module's job, but
flag it if the checkout page copy doesn't mention it).

## Library

Use **dompdf** (`composer require dompdf/dompdf`) — pure PHP, no external
binary/service dependency, adequate for a text+logo certificate layout. Do not
introduce a headless-Chrome/wkhtmltopdf dependency for this; it adds hosting
complexity (matches this client's HestiaCP/shared-resource hosting pattern)
that isn't justified for a one-page certificate.

## Trigger

Hook into the status engine's transition, not directly into
`woocommerce_payment_complete` (avoids double-triggering if a payment gateway
fires that hook more than once):

```php
add_action( 'woocommerce_order_status_changed', function( $order_id, $from, $to, $order ) {
    if ( $to === 'paid' ) {
        YKT_Certificate::generate_and_send( $order );
    }
}, 10, 4 );
```

Guard against re-generating on re-entry (e.g. admin manually re-triggers the
status) — check `$order->get_meta( '_certificate_number' )` first; only
generate a new one if empty, otherwise just resend the existing PDF (that's a
separate action, see `admin-dashboard` skill's "resend email" bulk action).

## Certificate numbering

Format: `YIARI-KG-{YYYY}-{sequential}` (KG = Karmila & Gito). Sequential number
must come from the status-log table's row count or a dedicated counter option
(`ykt_certificate_sequence`, incremented atomically via
`$wpdb->query( "UPDATE ... SET value = value + 1" )` or `wp_cache`-safe
increment) — do not derive it from `$order_id` (order IDs aren't guaranteed
sequential/gap-free and shouldn't be exposed to donors as a "certificate
number" for privacy reasons).

## Certificate content (from brief §6.B)

- Nama donatur
- Jenis kontribusi (Paket A / Paket B)
- Jumlah buku
- Tanggal
- Nomor sertifikat
- Nama program ("Petualangan Karmila & Gito, Menyelamatkan Orangutan")
- Logo YIARI

Template lives at `templates/certificate.php` — plain PHP+HTML with inline CSS
(dompdf has limited CSS support, keep it simple: no flexbox/grid, use
tables/absolute positioning for layout, embed logo as base64 to avoid
filesystem path issues in dompdf's sandboxed rendering).

## Storage & delivery

- Save PDF to `wp-content/uploads/ykt-certificates/{order_id}-{cert_number}.pdf`
  — this path is **not** meant to be guessable/public; add a `.htaccess`
  (Apache) or equivalent Nginx rule blocking direct directory listing, and only
  serve the file via a signed-nonce download link from the admin dashboard or
  the order-received page, never a bare public URL emailed as-is if avoidable.
  If the hosting stack serves uploads directly (check with the site's existing
  Nginx config, per this developer's usual HestiaCP setup), at minimum disable
  directory indexing and use an unguessable filename component (not just
  order_id).
- Attach to the confirmation email (see `email-engine` skill) as a file
  attachment, not just a link, so donors keep it regardless of link expiry.
- Store the relative path in order meta `_certificate_path` for the admin
  dashboard's "resend" and "view" actions.

## Manual QA

- [ ] Sandbox payment success generates exactly one PDF, one certificate number,
      even if the status-changed hook somehow fires twice.
- [ ] PDF renders correctly with a real donor name containing special
      characters (apostrophe, non-Latin script) — dompdf UTF-8 handling can be
      finicky, test explicitly.
- [ ] Paket A and Paket B certificates both render (contribution type label
      differs, quantity reflects actual qty ordered).
- [ ] Certificate file is not accessible via a guessed/sequential URL.
