# CrowdEase — API Contract v1.0

Dokumen ini adalah **kontrak resmi** untuk semua endpoint API CrowdEase. Frontend, IoT simulator, dan webhook receiver eksternal mengikuti spesifikasi ini. Dokumen ini juga menjadi acuan untuk Postman Collection dan dokumentasi Swagger/OpenAPI yang akan kalian generate.

---

## 1. Konvensi Umum

### 1.1 Base URL

```
http://localhost:8000/api/v1
```

Saat deploy:
```
https://crowdease.example.com/api/v1
```

### 1.2 Versioning

Semua endpoint pakai prefix `/api/v1/`. Aturan main:
- Versi minor (v1.0 → v1.1) **tidak boleh** memecah backward compatibility
- Penambahan field response = OK (klien lama harus mengabaikan field tak dikenal)
- Penghapusan/rename field = bikin v2 baru
- Saat v2 dirilis, v1 tetap hidup minimal 6 bulan

### 1.3 Authentication

Sistem ini punya **3 lapis auth** yang berbeda untuk konsumer berbeda:

| Konsumer       | Mekanisme                          | Header                              |
|----------------|------------------------------------|-------------------------------------|
| Public (passenger app) | Tidak ada / opsional       | —                                   |
| IoT Simulator  | API Key                            | `X-API-Key: <key>`                  |
| Operator       | Sanctum bearer token               | `Authorization: Bearer <token>`     |

Operator login pakai email + password lalu menerima token. Token disimpan di tabel `personal_access_tokens` (bawaan Sanctum).

API key untuk IoT di-generate sekali oleh operator via dashboard, lalu di-paste ke environment variable simulator. Key disimpan **hashed** di database (pakai `Hash::make()`), nilai aslinya hanya muncul satu kali saat dibuat.

### 1.4 Response Format

**Success:**
```json
{
  "success": true,
  "data": { ... },
  "meta": { ... }
}
```

**Error:**
```json
{
  "success": false,
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "The given data was invalid.",
    "details": {
      "passenger_count": ["The passenger count must be at least 0."]
    }
  }
}
```

### 1.5 HTTP Status Codes

| Status | Pakai untuk                                    |
|--------|------------------------------------------------|
| 200    | GET berhasil                                   |
| 201    | POST berhasil (resource baru terbuat)          |
| 204    | DELETE berhasil (no content)                   |
| 400    | Request format salah                           |
| 401    | Auth tidak ada / token kadaluarsa              |
| 403    | Auth ada tapi tidak punya hak akses            |
| 404    | Resource tidak ditemukan                       |
| 422    | Validation error (field tidak valid)           |
| 429    | Rate limit terlampaui                          |
| 500    | Error tak terduga di server                    |

### 1.6 Error Codes (di-field `error.code`)

```
VALIDATION_FAILED       — payload tidak valid
UNAUTHORIZED            — token/api key salah/tidak ada
FORBIDDEN               — token valid tapi role salah
NOT_FOUND               — resource tidak ada
DUPLICATE_RESOURCE      — bentrok unique constraint (mis. plate_number)
RATE_LIMIT_EXCEEDED     — terlalu banyak request
INTERNAL_ERROR          — error tak terduga
```

### 1.7 Pagination

Semua list endpoint pakai cursor pagination Laravel:

```
GET /api/v1/admin/density-logs?per_page=20&page=2
```

Response:
```json
{
  "success": true,
  "data": [ ... ],
  "meta": {
    "current_page": 2,
    "per_page": 20,
    "total": 142,
    "last_page": 8
  }
}
```

### 1.8 Rate Limiting

| Endpoint group       | Limit                  |
|----------------------|------------------------|
| Public               | 60 req/menit per IP    |
| IoT (X-API-Key)      | 600 req/menit per key  |
| Operator             | 120 req/menit per user |

Saat terlampaui: HTTP 429 + header `Retry-After: <seconds>`.

---

## 2. Endpoint Catalog

### 2.1 Public Endpoints (no auth)

| Method | Path                                       | Deskripsi                              |
|--------|--------------------------------------------|----------------------------------------|
| GET    | `/routes`                                  | List semua koridor aktif               |
| GET    | `/routes/{id}`                             | Detail satu koridor + halte            |
| GET    | `/routes/{id}/vehicles`                    | List bus di koridor + density terkini  |
| GET    | `/vehicles/{id}/density/current`           | Density terkini satu bus               |
| GET    | `/vehicles/{id}/density/forecast`          | Forecast 5/10/15 menit ke depan        |
| GET    | `/vehicles/{id}/density/history`           | History density (untuk chart publik)   |

### 2.2 IoT Endpoints (X-API-Key)

| Method | Path                            | Deskripsi                              |
|--------|---------------------------------|----------------------------------------|
| POST   | `/sensors/readings`             | Submit satu reading sensor             |
| POST   | `/sensors/readings/batch`       | Submit banyak reading sekaligus        |

### 2.3 Operator Endpoints (Bearer token)

**Auth:**
| Method | Path                | Deskripsi                |
|--------|---------------------|--------------------------|
| POST   | `/auth/login`       | Login, return token      |
| POST   | `/auth/logout`      | Revoke current token     |
| GET    | `/auth/me`          | Current user info        |

**Dashboard & analytics:**
| Method | Path                                  | Deskripsi                              |
|--------|---------------------------------------|----------------------------------------|
| GET    | `/admin/dashboard/stats`              | Ringkasan: total bus, avg occupancy    |
| GET    | `/admin/analytics/hourly`             | Data tren per-jam untuk Chart.js       |
| GET    | `/admin/density-logs`                 | List density log (paginated, filter)   |

**CRUD master data:**
| Method | Path                          | Deskripsi                          |
|--------|-------------------------------|------------------------------------|
| GET    | `/admin/routes`               | List semua route (incl. inactive)  |
| POST   | `/admin/routes`               | Buat route baru                    |
| PUT    | `/admin/routes/{id}`          | Update route                       |
| DELETE | `/admin/routes/{id}`          | Soft delete route                  |
| GET    | `/admin/vehicles`             | List bus                           |
| POST   | `/admin/vehicles`             | Tambah bus                         |
| PUT    | `/admin/vehicles/{id}`        | Update bus                         |
| DELETE | `/admin/vehicles/{id}`        | Hapus bus                          |
| GET    | `/admin/stops`                | List halte                         |
| POST   | `/admin/stops`                | Tambah halte                       |
| PUT    | `/admin/stops/{id}`           | Update halte                       |
| DELETE | `/admin/stops/{id}`           | Hapus halte                        |

**API key management:**
| Method | Path                          | Deskripsi                          |
|--------|-------------------------------|------------------------------------|
| GET    | `/admin/api-keys`             | List api key (key tidak ditampilkan) |
| POST   | `/admin/api-keys`             | Buat key baru (key tampil sekali)  |
| DELETE | `/admin/api-keys/{id}`        | Revoke key                         |

**Webhook management (BONUS):**
| Method | Path                                | Deskripsi                          |
|--------|-------------------------------------|------------------------------------|
| GET    | `/admin/webhooks`                   | List registered webhooks           |
| POST   | `/admin/webhooks`                   | Daftarkan URL webhook baru         |
| PUT    | `/admin/webhooks/{id}`              | Update webhook                     |
| DELETE | `/admin/webhooks/{id}`              | Hapus webhook                      |
| POST   | `/admin/webhooks/{id}/test`         | Trigger test ping ke URL           |
| GET    | `/admin/webhooks/{id}/deliveries`   | Log pengiriman webhook             |

---

## 3. Detailed Endpoint Specifications

Bagian ini contoh request/response untuk endpoint paling penting. Pola yang sama berlaku untuk endpoint sejenis.

### 3.1 POST /sensors/readings (IoT)

**Request:**
```http
POST /api/v1/sensors/readings HTTP/1.1
Host: localhost:8000
Content-Type: application/json
X-API-Key: ce_iot_8f7a3b92c1d4e5f6...

{
  "vehicle_id": 12,
  "passenger_count": 47,
  "capacity_at_time": 60,
  "recorded_at": "2026-05-05T10:32:15+07:00"
}
```

**Validation:**
- `vehicle_id` — required, integer, harus exist di tabel vehicles
- `passenger_count` — required, integer, min 0
- `capacity_at_time` — required, integer, min 1
- `recorded_at` — required, ISO 8601 datetime, tidak boleh > 5 menit dari sekarang

**Response 201 Created:**
```json
{
  "success": true,
  "data": {
    "id": 8231,
    "vehicle_id": 12,
    "passenger_count": 47,
    "capacity_at_time": 60,
    "occupancy_ratio": 0.78,
    "occupancy_level": "high",
    "recorded_at": "2026-05-05T10:32:15+07:00",
    "forecast_triggered": true
  }
}
```

**Side effects:**
1. Insert ke `density_logs`
2. Calculate occupancy_level berdasarkan ratio:
   - `< 0.5` → "low" (hijau)
   - `0.5 - 0.8` → "medium" (kuning)
   - `> 0.8` → "high" (merah)
3. Trigger `ForecastingService::forecast($vehicleId)` untuk update tabel forecasts
4. Trigger event `DensityRecorded` (untuk webhook outbound, lihat section 4)

**Response 401 Unauthorized:**
```json
{
  "success": false,
  "error": {
    "code": "UNAUTHORIZED",
    "message": "Invalid or missing API key."
  }
}
```

### 3.2 GET /vehicles/{id}/density/current (Public)

**Request:**
```http
GET /api/v1/vehicles/12/density/current HTTP/1.1
Host: localhost:8000
```

**Response 200 OK:**
```json
{
  "success": true,
  "data": {
    "vehicle": {
      "id": 12,
      "plate_number": "B 7042 TRN",
      "route": {
        "id": 1,
        "code": "K1",
        "name": "Koridor 1: Blok M – Kota"
      }
    },
    "density": {
      "passenger_count": 47,
      "capacity": 60,
      "occupancy_ratio": 0.78,
      "occupancy_level": "high",
      "recorded_at": "2026-05-05T10:32:15+07:00",
      "age_seconds": 12
    }
  }
}
```

`age_seconds` berguna untuk frontend — kalau > 60 detik, tampilkan indikator "data lama".

### 3.3 GET /vehicles/{id}/density/forecast (Public)

**Request:**
```http
GET /api/v1/vehicles/12/density/forecast HTTP/1.1
Host: localhost:8000
```

**Response 200 OK:**
```json
{
  "success": true,
  "data": {
    "vehicle_id": 12,
    "current_count": 47,
    "forecasts": [
      {
        "predicted_for": "2026-05-05T10:37:00+07:00",
        "minutes_ahead": 5,
        "predicted_count": 52,
        "predicted_occupancy_level": "high"
      },
      {
        "predicted_for": "2026-05-05T10:42:00+07:00",
        "minutes_ahead": 10,
        "predicted_count": 55,
        "predicted_occupancy_level": "high"
      },
      {
        "predicted_for": "2026-05-05T10:47:00+07:00",
        "minutes_ahead": 15,
        "predicted_count": 49,
        "predicted_occupancy_level": "medium"
      }
    ],
    "model_version": "moving_avg_v1"
  }
}
```

### 3.4 POST /auth/login (Operator)

**Request:**
```http
POST /api/v1/auth/login HTTP/1.1
Content-Type: application/json

{
  "email": "operator@crowdease.test",
  "password": "secret123"
}
```

**Response 200 OK:**
```json
{
  "success": true,
  "data": {
    "user": {
      "id": 1,
      "name": "Admin Operator",
      "email": "operator@crowdease.test",
      "role": "admin"
    },
    "token": "1|abcdefghijklmnopqrstuvwxyz1234567890",
    "token_type": "Bearer"
  }
}
```

**Response 422:**
```json
{
  "success": false,
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "Email atau password salah.",
    "details": {
      "email": ["Kombinasi email dan password tidak cocok."]
    }
  }
}
```

### 3.5 POST /admin/webhooks (BONUS)

**Request:**
```http
POST /api/v1/admin/webhooks HTTP/1.1
Authorization: Bearer 1|abc...
Content-Type: application/json

{
  "name": "Slack notifier crowded",
  "url": "https://hooks.slack.com/services/T00/B00/XYZ",
  "events": ["density.high_threshold_crossed", "density.recorded"],
  "is_active": true
}
```

**Validation:**
- `url` — required, valid HTTPS URL (HTTP boleh di local)
- `events` — required, array minimal 1 event, dari list yang valid
- `name` — required, string, max 100 chars

**Response 201 Created:**
```json
{
  "success": true,
  "data": {
    "id": 3,
    "name": "Slack notifier crowded",
    "url": "https://hooks.slack.com/services/T00/B00/XYZ",
    "events": ["density.high_threshold_crossed", "density.recorded"],
    "secret": "whsec_8a7b6c5d4e3f2g1h...",
    "is_active": true,
    "created_at": "2026-05-05T10:00:00+07:00"
  }
}
```

`secret` di-generate saat create dan dipakai untuk HMAC signature di outbound payload (lihat section 4). Secret cuma muncul sekali — operator wajib catat.

**List of valid events:**
```
density.recorded                    # tiap reading masuk
density.high_threshold_crossed      # ratio > 0.8 (dari < 0.8 sebelumnya)
density.low_threshold_recovered     # ratio < 0.5 (dari ≥ 0.5 sebelumnya)
vehicle.created
vehicle.updated
```

### 3.6 GET /admin/analytics/hourly (Operator)

**Request:**
```http
GET /api/v1/admin/analytics/hourly?route_id=1&date=2026-05-05 HTTP/1.1
Authorization: Bearer 1|abc...
```

**Response 200 OK:**
```json
{
  "success": true,
  "data": {
    "route_id": 1,
    "date": "2026-05-05",
    "hourly_avg_occupancy": [
      { "hour": "00:00", "avg_ratio": 0.12, "sample_count": 24 },
      { "hour": "01:00", "avg_ratio": 0.08, "sample_count": 22 },
      { "hour": "06:00", "avg_ratio": 0.45, "sample_count": 89 },
      { "hour": "07:00", "avg_ratio": 0.82, "sample_count": 142 },
      { "hour": "08:00", "avg_ratio": 0.91, "sample_count": 156 }
    ]
  }
}
```

Frontend dashboard tinggal feed array ini ke Chart.js bar chart.

---

## 4. Webhook Outbound Payload (BONUS)

Saat event terjadi, Laravel kirim **POST request keluar** ke URL yang terdaftar. Format-nya **fixed contract** ini.

### 4.1 Outbound Request

```http
POST https://hooks.slack.com/services/T00/B00/XYZ HTTP/1.1
Content-Type: application/json
User-Agent: CrowdEase-Webhook/1.0
X-CrowdEase-Event: density.high_threshold_crossed
X-CrowdEase-Delivery-Id: 7a8b9c0d1e2f
X-CrowdEase-Signature: sha256=8f4a...
X-CrowdEase-Timestamp: 1730800000

{
  "event": "density.high_threshold_crossed",
  "delivered_at": "2026-05-05T10:32:18+07:00",
  "data": {
    "vehicle": {
      "id": 12,
      "plate_number": "B 7042 TRN",
      "route_code": "K1"
    },
    "density": {
      "passenger_count": 50,
      "capacity": 60,
      "occupancy_ratio": 0.83,
      "previous_ratio": 0.75,
      "recorded_at": "2026-05-05T10:32:15+07:00"
    }
  }
}
```

### 4.2 Signature Verification (untuk consumer)

Receiver wajib verifikasi signature:

```
expected = "sha256=" + hmac_sha256(payload_body, webhook_secret)
if header X-CrowdEase-Signature != expected: reject
```

Ini standard pattern (mirip GitHub webhooks). Pattern ini juga yang akan kalian highlight di laporan untuk poin keamanan integrasi.

### 4.3 Retry Policy

| Attempt | Delay setelah gagal |
|---------|---------------------|
| 1       | langsung            |
| 2       | +30 detik           |
| 3       | +5 menit            |
| 4       | +30 menit           |
| 5       | +6 jam              |

Setelah attempt ke-5 gagal: webhook ditandai gagal di tabel `webhook_deliveries`, tidak di-retry lagi. Dianggap sukses kalau receiver return status 2xx.

---

## 5. Data Format & Conventions

### 5.1 Datetime
Selalu ISO 8601 dengan timezone offset:
```
2026-05-05T10:32:15+07:00
```
Server menyimpan di UTC, tapi response selalu dalam timezone Asia/Jakarta untuk kemudahan demo.

### 5.2 Occupancy Level Mapping

| Ratio range  | Level    | Warna marker |
|--------------|----------|--------------|
| 0.00 – 0.49  | low      | green        |
| 0.50 – 0.79  | medium   | yellow       |
| 0.80 – 1.00  | high     | red          |
| > 1.00       | overcrowded | dark red  |

### 5.3 Sample Route Codes (untuk seeder)

| Code | Name                                   |
|------|----------------------------------------|
| K1   | Koridor 1: Blok M – Kota               |
| K2   | Koridor 2: Pulo Gadung – Harmoni       |
| K3   | Koridor 3: Kalideres – Pasar Baru      |
| K9   | Koridor 9: Pinang Ranti – Pluit        |
| K13  | Koridor 13: Ciledug – Tendean          |

---

## 6. Quick Reference: Auth Decision Table

Saat coding setiap endpoint, refer table ini:

| Path pattern                | Middleware                  | Header yang dibutuhkan      |
|-----------------------------|-----------------------------|-----------------------------|
| `/api/v1/routes*`           | `throttle:public`           | —                           |
| `/api/v1/vehicles/*/density*` | `throttle:public`         | —                           |
| `/api/v1/sensors/*`         | `auth.api-key,throttle:iot` | `X-API-Key`                 |
| `/api/v1/auth/login`        | `throttle:auth`             | —                           |
| `/api/v1/auth/logout`       | `auth:sanctum`              | `Authorization: Bearer`     |
| `/api/v1/admin/*`           | `auth:sanctum,role:admin`   | `Authorization: Bearer`     |

---

## 7. Postman Collection / OpenAPI

Gunakan tools ini untuk auto-generate dokumentasi yang siap presentasi:

**Scribe** (recommended untuk Laravel):
```bash
composer require knuckleswtf/scribe
php artisan scribe:generate
```
Hasilnya HTML doc + Postman + OpenAPI spec di `public/docs`.

**L5-Swagger** (alternatif):
```bash
composer require darkaonline/l5-swagger
```

Pilih salah satu di awal projek. Anggaplah dokumentasi sebagai **deliverable mata kuliah** yang sama pentingnya dengan kode — di mata kuliah integrasi sistem, kontrak API yang jelas adalah inti penilaiannya.

---

## 8. Changelog

| Version | Date         | Changes                            |
|---------|--------------|-----------------------------------|
| 1.0     | 2026-05-05   | Initial draft (MVP + bonus features) |
