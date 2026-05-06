# Backend — CrowdEase Laravel API

Folder ini akan berisi aplikasi Laravel sebagai integration hub. **Belum diisi** karena Laravel akan di-create melalui Composer setelah kalian setup environment lokal.

## Setup Pertama Kali

Dari folder `crowdease/` (root repo):

```bash
cd backend

# Install Laravel ke dalam folder ini
composer create-project laravel/laravel . "11.*"

# Setup environment
cp .env.example .env
php artisan key:generate

# Setelah konfigurasi DB di .env, jalankan migrasi
php artisan migrate

# Jalankan seeder (akan dibuat tim)
php artisan db:seed
```

**Tip**: Pakai `composer create-project laravel/laravel .` (dengan titik) agar Laravel diinstall di folder ini, bukan membuat subfolder baru.

## Konfigurasi `.env` Backend

Setelah Laravel diinstall, edit `backend/.env`:

```env
APP_NAME=CrowdEase
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000

# Pakai timezone Indonesia agar timestamp sesuai
APP_TIMEZONE=Asia/Jakarta

# Database (cocokkan dengan docker-compose root)
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=crowdease
DB_USERNAME=crowdease
DB_PASSWORD=crowdease_secret

# Untuk dokumentasi API auto-generate
SCRIBE_AUTH_KEY=
```

## Package yang Akan Diinstall

```bash
# Core
composer require laravel/sanctum
composer require knuckleswtf/scribe --dev

# Frontend asset toolchain (Tailwind sudah include di Laravel 11)
npm install
npm install --save-dev tailwindcss-forms
```

## Organisasi Folder yang Disarankan

Setelah Laravel terinstall, ikuti struktur ini agar konsisten dengan dokumen perancangan:

```
backend/app/
├── Http/
│   ├── Controllers/
│   │   └── Api/V1/                    ← Versioned controllers
│   │       ├── Public/                ← TI-3: penumpang (no auth)
│   │       │   ├── RouteController.php
│   │       │   ├── VehicleController.php
│   │       │   └── DensityController.php
│   │       ├── Iot/                   ← TI-1: dari simulator (X-API-Key)
│   │       │   └── SensorReadingController.php
│   │       └── Admin/                 ← TI-4: operator (Sanctum)
│   │           ├── AuthController.php
│   │           ├── DashboardController.php
│   │           ├── RouteController.php
│   │           ├── VehicleController.php
│   │           ├── StopController.php
│   │           ├── ApiKeyController.php
│   │           └── WebhookController.php
│   ├── Middleware/
│   │   ├── ApiKeyAuth.php             ← Validasi X-API-Key
│   │   └── EnsureOperatorRole.php
│   ├── Requests/Api/V1/               ← Form request validators
│   └── Resources/Api/V1/              ← API response transformers
├── Models/                            ← 9 tabel
├── Services/
│   ├── ForecastingService.php         ← Logic prediksi
│   └── WebhookDispatcher.php          ← Logic webhook outbound
├── Events/                            ← Event broadcasting
├── Listeners/                         ← Event handlers
└── Jobs/                              ← Queued jobs (retry webhook)

backend/routes/
├── api.php                            ← Top-level: include v1
├── api/v1/
│   ├── public.php
│   ├── iot.php
│   └── admin.php
└── web.php                            ← Halaman web (penumpang & admin UI)

backend/resources/views/
├── layouts/
├── passenger/                         ← UI penumpang
└── admin/                             ← UI dasbor operator
```

## Migrasi yang Perlu Dibuat

Buat 9 file migrasi dalam urutan ini:

```bash
php artisan make:migration create_routes_table
php artisan make:migration create_stops_table
php artisan make:migration create_vehicles_table
php artisan make:migration create_density_logs_table
php artisan make:migration create_forecasts_table
php artisan make:migration create_api_keys_table
php artisan make:migration create_webhooks_table
php artisan make:migration create_webhook_deliveries_table
# Sanctum sudah membuat personal_access_tokens otomatis
```

Skema detail tiap kolom dirujuk di `docs/DPPL_CrowdEase.docx` BAB IV.3 dan `docs/ARCHITECTURE.md`.

## Generate Dokumentasi API

Setelah controller selesai dan PHPDoc-nya rapi:

```bash
php artisan scribe:generate
```

Hasil otomatis di `public/docs/`:
- HTML interaktif → `public/docs/index.html`
- Postman collection → `public/docs/collection.json`
- OpenAPI YAML → `public/docs/openapi.yaml`

## Menjalankan Backend

```bash
# Development server
php artisan serve

# Akan running di http://localhost:8000
```

Atau dengan Docker (uncomment service backend di `docker-compose.yml` root):

```bash
docker-compose up backend
```

## Testing

```bash
# Run all tests
php artisan test

# Hanya feature tests (test endpoint dengan database)
php artisan test --testsuite=Feature

# Dengan coverage report
php artisan test --coverage
```

## Tip Demo

Sebelum demo akhir, pastikan:

- [ ] `php artisan migrate:fresh --seed` jalan tanpa error
- [ ] User operator default sudah dibuat oleh seeder (`operator@crowdease.test` / `secret123`)
- [ ] Sample API key sudah dibuat oleh seeder dan di-print ke console (untuk dipakai simulator)
- [ ] `php artisan scribe:generate` jalan dan dokumentasi tersedia di `/docs`
- [ ] Database sudah punya 5 koridor + 7 armada + sample halte
