# Arsitektur Sistem CrowdEase

Dokumen ini adalah ringkasan arsitektur untuk **referensi cepat** saat coding. Untuk dokumentasi formal yang lengkap (untuk submit), lihat [`DPPL_CrowdEase.docx`](DPPL_CrowdEase.docx) BAB IV dan V.

## Diagram Konseptual

```
┌──────────────────┐                    ┌──────────────────┐
│  IoT Simulator   │                    │  OpenStreetMap   │
│  (Python script) │                    │  (Tile Provider) │
└────────┬─────────┘                    └─────────┬────────┘
         │ POST /sensors/readings                 │ GET tiles
         │ X-API-Key: ce_iot_...                  │ (langsung dari frontend)
         ▼                                        │
┌─────────────────────────────────────────┐       │
│         BACKEND SERVICES                │       │
│  ┌──────────────────────┐               │       │
│  │   Laravel REST API   │◄──────────────┼───────┘
│  │  (Integration Hub)   │               │
│  │   - Auth (Sanctum)   │               │
│  │   - Forecasting Svc  │               │
│  │   - Webhook Dispatch │               │
│  └──────┬───────────────┘               │
│         │                               │
│         ▼                               │
│  ┌──────────────────────┐               │
│  │   MySQL Database     │               │
│  │   (9 tabel)          │               │
│  └──────────────────────┘               │
└────────┬───────────┬───────────┬────────┘
         │           │           │
         ▼           ▼           ▼
   ┌─────────┐ ┌──────────┐ ┌──────────┐
   │Penumpang│ │ Operator │ │ Webhook  │
   │   App   │ │ Dashboard│ │ Eksternal│
   │(polling)│ │  (CRUD)  │ │ (Slack)  │
   └─────────┘ └──────────┘ └──────────┘
```

## 5 Titik Integrasi API

| Kode | Sumber | Tujuan | Protokol | Auth |
|------|--------|--------|----------|------|
| **TI-1** | IoT Simulator | Backend | REST POST | API Key (X-API-Key header) |
| **TI-2** | Backend | Webhook Receiver | REST POST outbound | HMAC SHA-256 signature |
| **TI-3** | Backend | Passenger App | REST GET (polling 5s) | None (public) |
| **TI-4** | Backend | Operator Dashboard | REST CRUD | Sanctum bearer token |
| **TI-5** | Passenger App | OpenStreetMap | HTTP tile request | None |

## Keputusan Arsitektur Penting

### 1. Forecasting di dalam Laravel (bukan microservice terpisah)

Pertimbangan tim PHP-only dan timeline 4 minggu. `ForecastingService` adalah class biasa di `app/Services/` yang dipanggil dari controller. Implementasi awal pakai moving average sederhana atas 5 data terakhir.

Di DPPL ini didokumentasikan sebagai keputusan arsitektur dengan trade-off yang dipertimbangkan, bukan sebagai kelemahan.

### 2. Polling 5s, bukan WebSocket

Lebih sederhana, lebih reliable saat demo. Trade-off-nya: ada delay maksimal 5 detik antara perubahan data dan tampilan UI. Untuk konteks transportasi umum (kepadatan tidak berubah dalam hitungan milidetik), delay ini tidak material.

Dapat di-upgrade ke WebSocket via Laravel Reverb di iterasi berikutnya tanpa breaking change ke endpoint REST.

### 3. Tiga Lapis Autentikasi

| Konsumer | Mekanisme | Alasan |
|----------|-----------|--------|
| Penumpang (publik) | Tanpa auth | Read-only, info publik. Friction auth menghalangi adopsi. |
| IoT Simulator | API Key | Machine-to-machine. API key cocok untuk komunikasi stabil. |
| Operator | Sanctum token | User manusia, hak akses tinggi, butuh revokable. |

Pemisahan ini bukan hanya soal keamanan tapi cerminan prinsip: **API yang sama bisa dikonsumsi banyak klien dengan kebutuhan auth berbeda**.

### 4. Versioning via URL Prefix (`/api/v1/`)

Eksplisit, mudah debug, kompatibel dengan tooling cache. Saat v2 dirilis, v1 tetap hidup minimal 6 bulan sebagai komitmen kompatibilitas mundur.

Implementasi: file route terpisah per versi (`routes/api/v1.php`, `routes/api/v2.php`), namespace controller terpisah.

### 5. Webhook Outbound dengan HMAC

Penerima webhook eksternal dapat memverifikasi keaslian payload menggunakan HMAC SHA-256 dengan secret yang di-share saat pendaftaran. Pola standar industri (GitHub, Stripe, Slack pakai pola sama).

Retry policy eksponensial: 0s, 30s, 5m, 30m, 6h. Setelah 5x gagal, ditandai failed di tabel `webhook_deliveries`.

## Struktur Data Inti

```
ROUTES (koridor TJ)
  └── STOPS (halte, banyak per koridor)
  └── VEHICLES (bus, banyak per koridor)
        └── DENSITY_LOGS (banyak pencatatan per bus)
        └── FORECASTS (banyak prediksi per bus)

WEBHOOKS (URL eksternal terdaftar)
  └── WEBHOOK_DELIVERIES (log pengiriman per webhook)

USERS (operator)
API_KEYS (untuk IoT)
PERSONAL_ACCESS_TOKENS (Sanctum, otomatis)
```

## Struktur Folder Backend (Laravel)

Setelah Laravel terinstall, file akan diorganisir seperti ini:

```
backend/app/
├── Http/
│   ├── Controllers/Api/V1/
│   │   ├── Public/        ← Endpoint tanpa auth (untuk penumpang)
│   │   ├── Iot/           ← Endpoint untuk IoT (X-API-Key)
│   │   └── Admin/         ← Endpoint operator (Sanctum)
│   ├── Middleware/
│   │   └── ApiKeyAuth.php ← Validasi X-API-Key
│   ├── Requests/Api/V1/   ← Form request validators
│   └── Resources/Api/V1/  ← API response transformers
├── Models/
│   ├── User.php
│   ├── ApiKey.php
│   ├── Route.php
│   ├── Stop.php
│   ├── Vehicle.php
│   ├── DensityLog.php
│   ├── Forecast.php
│   ├── Webhook.php
│   └── WebhookDelivery.php
├── Services/
│   ├── ForecastingService.php   ← Algoritma prediksi
│   └── WebhookDispatcher.php    ← Logic dispatch outbound
├── Events/
│   ├── DensityRecorded.php
│   └── DensityHighThresholdCrossed.php
├── Listeners/
│   └── DispatchWebhooks.php
└── Jobs/
    └── DeliverWebhook.php       ← Queued job dengan retry policy
```

Routes:
```
backend/routes/
├── api.php                   ← Top level: load v1.php
└── api/v1/
    ├── public.php            ← TI-3
    ├── iot.php               ← TI-1
    └── admin.php             ← TI-4
```

## Untuk Detail Lengkap

- **API endpoints rinci** → `API_CONTRACT.md`
- **Skema database & ERD** → DPPL Bab IV.3
- **Use case lengkap** → DPPL Lampiran A
- **Kebutuhan fungsional FR-001 sampai FR-023** → DPPL Bab III.1
