# Karmila & Gito Campaign

Paket ini berisi preview HTML responsif berdasarkan desain Canva **Copy of Bahan website Fundaising Karmila Gito**.

## Struktur

- `index.html`: entry point HTML.
- `css/campaign.css`: breakpoint desktop/mobile.
- `assets/pages/png/`: thumbnail halaman Canva dalam PNG untuk referensi.
- `assets/pages/webp/`: versi WebP teroptimasi untuk browser.
- Desktop: halaman Canva 1-14.
- Mobile: halaman Canva 15-21.

Buka `index.html` langsung di browser atau sajikan folder ini melalui web server. Pada lebar layar minimal 768px, versi desktop tampil; di bawahnya versi mobile tampil.

## Versi sliced

`sliced.html` adalah versi HTML/CSS terpisah: teks, CTA/button, kartu paket, progress, dan FAQ dibuat sebagai elemen HTML. File ini tidak menggantikan `index.html` referensi pixel-accurate.

## Aset individual

Enam aset individual yang diizinkan Canva tersedia di `assets/elements/png/` dan `assets/elements/webp/`: shadow, check mark, organic blob, recommended badge, dropdown, dan arrow right.

## Catatan aset

API Canva mengizinkan akses ke thumbnail halaman, tetapi menolak ekspor aset elemen individual karena permission aset. Karena itu folder ini memakai thumbnail halaman resmi Canva sebagai fallback pixel-accurate, bukan menyalin aset privat individual.
