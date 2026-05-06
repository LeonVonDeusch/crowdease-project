# Scripts

Folder berisi helper scripts untuk otomasi tugas berulang.

## `setup.sh` — First-time Setup

Script otomasi untuk anggota tim baru atau saat clone fresh repository. Memeriksa prasyarat (PHP, Composer, Python, Docker), install Laravel kalau belum ada, install dependencies, dan generate kunci-kunci awal.

### Cara Pakai

Dari folder root project:

```bash
chmod +x scripts/setup.sh    # sekali saja, kasih permission execute
./scripts/setup.sh
```

Script ini **idempotent** — aman dijalankan ulang. Yang sudah terinstall akan dilewati.

### Yang Dilakukan

1. ✓ Cek prasyarat (PHP 8.2+, Composer, Python 3.10+, Docker opsional)
2. ✓ Start MySQL container (kalau Docker tersedia)
3. ✓ Install Laravel ke `backend/` (kalau belum)
4. ✓ Install Composer dependencies
5. ✓ Generate `APP_KEY` Laravel
6. ✓ Install Sanctum dan Scribe
7. ✓ Buat Python venv di `iot-simulator/venv`
8. ✓ Install Python dependencies
9. ✓ Print petunjuk langkah selanjutnya

### Tip

Saat onboarding anggota baru, cukup arahkan mereka ke:

```bash
git clone <repo>
cd crowdease
./scripts/setup.sh
```

Selesai dalam ~5 menit (tergantung kecepatan internet untuk download dependencies).

## Script Lain (untuk dikembangkan tim)

Ide untuk script tambahan yang berguna:

- **`scripts/seed-demo.sh`** — Reset DB + seed data demo + buat API key + tampilkan ke console (untuk persiapan demo cepat)
- **`scripts/run-all.sh`** — Jalankan backend + simulator dalam tmux session (untuk demo)
- **`scripts/lint.sh`** — Cek standar kode (PHP CS Fixer, Black untuk Python)
- **`scripts/test.sh`** — Run semua test (PHPUnit + Python tests)

Silakan tambahkan sesuai kebutuhan tim.
