# Postman Collection

Folder untuk Postman collection yang dipakai saat testing API manual dan demo.

## Cara Generate Collection

Setelah backend berjalan dan endpoint sudah ada, generate otomatis dengan Scribe:

```bash
cd ../backend
php artisan scribe:generate
```

Lalu copy hasilnya ke folder ini:

```bash
cp ../backend/public/docs/collection.json ./CrowdEase.postman_collection.json
```

## Cara Import ke Postman

1. Buka Postman → klik **Import** (kiri atas)
2. Drag file `CrowdEase.postman_collection.json` ke dialog
3. Collection muncul di sidebar dengan semua endpoint

## Setup Environment Postman

Buat environment baru dengan variable:

| Variable | Value |
|----------|-------|
| `base_url` | `http://localhost:8000` |
| `api_v1` | `{{base_url}}/api/v1` |
| `iot_api_key` | (paste API key dari operator dashboard) |
| `operator_token` | (otomatis terisi setelah test login) |

## Test Flow Manual

Urutan test yang biasa dipakai untuk verifikasi end-to-end:

1. **Public endpoint** — `GET {{api_v1}}/routes`
   - Tidak butuh auth
   - Verifikasi data koridor seeder muncul

2. **Login operator** — `POST {{api_v1}}/auth/login`
   - Body: `{"email": "operator@crowdease.test", "password": "secret123"}`
   - Tab **Tests** auto-set `operator_token`:
     ```javascript
     pm.environment.set("operator_token", pm.response.json().data.token);
     ```

3. **Operator endpoint** — `GET {{api_v1}}/admin/dashboard/stats`
   - Header: `Authorization: Bearer {{operator_token}}`
   - Verifikasi response 200 dengan stats

4. **Buat API key** — `POST {{api_v1}}/admin/api-keys`
   - Catat API key yang muncul (hanya sekali!)
   - Set ke `iot_api_key`

5. **IoT endpoint** — `POST {{api_v1}}/sensors/readings`
   - Header: `X-API-Key: {{iot_api_key}}`
   - Body: `{"vehicle_id": 1, "passenger_count": 35, "capacity_at_time": 60, "recorded_at": "2026-05-05T10:32:15+07:00"}`
   - Verifikasi 201 Created

6. **Webhook** — `POST {{api_v1}}/admin/webhooks`
   - Daftarkan URL dari [webhook.site](https://webhook.site)
   - Trigger sensor reading dengan kepadatan tinggi
   - Verifikasi webhook masuk di webhook.site

## Tip Demo

Saat demo akhir, **siapkan tab Postman terpisah** untuk masing-masing skenario yang akan didemoin. Contoh:
- Tab 1: Login (tinggal klik Send untuk dapat token)
- Tab 2: POST sensor reading dengan high passenger_count
- Tab 3: GET density current (untuk verifikasi data masuk)

Postman runner juga bisa dipakai untuk men-trigger banyak request berturut-turut sebagai stress test ringan.
