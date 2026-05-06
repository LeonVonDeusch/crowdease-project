# CrowdEase IoT Simulator

Simulator perangkat IoT untuk armada bus TransJakarta yang mengirim data jumlah penumpang ke backend CrowdEase. Pengganti perangkat keras (kamera + ESP32 + dll) yang akan dipakai di sistem produksi.

## Setup

Pastikan Python 3.10+ terpasang. Lalu:

```bash
# 1. Buat virtual environment (opsional tapi disarankan)
python -m venv venv
source venv/bin/activate          # macOS/Linux
# venv\Scripts\activate            # Windows

# 2. Install dependencies
pip install -r requirements.txt

# 3. Salin & isi file konfigurasi
cp .env.example .env
# Edit .env, isi CROWDEASE_API_KEY dengan key yang valid
```

## Mendapatkan API Key

API key dibuat sekali oleh **operator** via dashboard admin CrowdEase:

1. Login ke dasbor operator (`http://localhost:8000/admin`)
2. Buka menu **API Keys** → klik **Buat API Key Baru**
3. Isi nama (mis. "IoT Simulator Lokal")
4. Salin key yang ditampilkan (hanya muncul **sekali**)
5. Tempelkan ke variabel `CROWDEASE_API_KEY` di file `.env`

## Cara Pakai

### Mode kontinu (default — untuk demo live)

```bash
python simulator.py
```

Output: log warna-warni dengan indikator kepadatan (hijau/kuning/merah) yang update setiap 2 detik. Tekan `Ctrl+C` untuk berhenti.

### Mode burst (untuk testing cepat)

Kirim sekali untuk setiap armada lalu langsung keluar:

```bash
python simulator.py --burst
```

Cocok untuk smoke test setelah deploy backend baru.

### Mode timer

Jalan selama N detik lalu otomatis berhenti (berguna untuk demo dengan durasi terbatas):

```bash
python simulator.py --duration 60
```

### Custom tick interval

Default mengirim 1 reading tiap 2 detik. Untuk demo lebih cepat:

```bash
python simulator.py --tick 1
```

Untuk demo lebih santai:

```bash
python simulator.py --tick 5
```

### Custom jumlah armada

Default mensimulasikan 5 armada. Untuk lebih banyak/sedikit:

```bash
python simulator.py --vehicles 7
python simulator.py --vehicles 3
```

## Pola Data yang Dihasilkan

Simulator menghasilkan data **realistis**, bukan random murni:

- **Jam sibuk pagi (07:00-09:00)** dan **sore (17:00-19:00)** → kepadatan menuju ~95% kapasitas
- **Off-peak** → kepadatan stabil di sekitar 20% kapasitas
- **Random walk** dengan noise ±3 penumpang per tick → tidak ada lompatan ekstrem
- **Setiap kendaraan punya baseline berbeda** (±10%) untuk variasi alami

Pola ini membuat data lebih believable saat demo dan memberi forecast algorithm
data yang masuk akal untuk diprediksi.

## Output Terminal

Saat berjalan, simulator menampilkan log seperti ini:

```
[10:32:15] ✓  B 7001 TRN  K1     47/60   78%  HIGH
[10:32:17] ✓  B 7002 TRN  K1     32/60   53%  MED
[10:32:19] ✓  B 7011 TRN  K2     12/60   20%  LOW
[10:32:21] ✗  B 7012 TRN  K2     HTTP 401: Invalid API key
```

- `✓` hijau = sukses
- `✗` merah = gagal (dengan pesan error)
- Persentase berwarna mengikuti tingkat kepadatan (LOW/MED/HIGH)

## Daftar Armada Default

Simulator memuat 7 armada hardcoded yang ID-nya **harus match** dengan tabel `vehicles` di database backend (hasil seeder Laravel):

| ID | Plat        | Koridor | Kapasitas |
|----|-------------|---------|-----------|
| 1  | B 7001 TRN  | K1      | 60        |
| 2  | B 7002 TRN  | K1      | 60        |
| 3  | B 7011 TRN  | K2      | 60        |
| 4  | B 7012 TRN  | K2      | 80        |
| 5  | B 7021 TRN  | K3      | 60        |
| 6  | B 7031 TRN  | K9      | 60        |
| 7  | B 7041 TRN  | K13     | 80        |

Jika seeder Laravel kalian menghasilkan ID yang berbeda, edit konstanta `DEFAULT_VEHICLES` di `simulator.py`.

## Troubleshooting

**`Backend tidak menjawab`** → pastikan Laravel berjalan di URL yang benar:
```bash
cd ../backend && php artisan serve
```

**`HTTP 401: Invalid API key`** → API key di `.env` salah atau sudah dicabut. Generate ulang via dasbor operator.

**`HTTP 422: validation failed`** → vehicle_id tidak ada di database. Pastikan `php artisan migrate --seed` sudah dijalankan di backend.

**Output terminal tidak berwarna** → set `NO_COLOR=` di environment, atau pipe output ke file (warna otomatis dimatikan untuk non-tty).

## Integration dengan Demo

Saat demo akhir, jalankan simulator di Terminal kedua dengan setting cepat:

```bash
python simulator.py --tick 1
```

Penonton akan melihat data terus mengalir dan UI passenger app akan update setiap 5 detik (interval polling) sesuai data terbaru. Untuk men-trigger **threshold high crossing** secara dramatis, biarkan jalan beberapa menit di pagi hari simulasi atau bisa dipaksa dengan menambah `tick` yang sangat cepat.

## Catatan Teknis

- Pakai `requests.Session` untuk reuse koneksi TCP → lebih efisien
- Sigint handler bersih: Ctrl+C menampilkan ringkasan request sebelum keluar
- Tidak ada file state — restart simulator akan mulai dari random state baru
- Format datetime mengikuti API contract: ISO 8601 dengan offset WIB (+07:00)
