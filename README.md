# CrowdEase

> Sistem Deteksi Kepadatan Transportasi Umum berbasis API dan IoT (simulasi)

[![Status](https://img.shields.io/badge/status-development-yellow)]() [![License](https://img.shields.io/badge/license-MIT-green)]() [![PHP](https://img.shields.io/badge/PHP-8.2+-blue)]() [![Python](https://img.shields.io/badge/Python-3.10+-blue)]()

Proyek akhir mata kuliah **Teknologi Integrasi Sistem**. Mengadaptasi konsep [CrowdEase ITB](https://itb.ac.id/berita/tim-muridpaksoni-raih-best-paper-gemastik-xvii-lewat-inovasi-crowdease-sistem-deteksi-kepadatan-transportasi-umum-berbasis-ai-dan-iot/63030) (Best Paper GEMASTIK XVII 2025) sebagai studi kasus penerapan teknologi integrasi sistem dalam domain kota cerdas.

## Apa yang dibangun

Sistem deteksi kepadatan armada bus TransJakarta dengan **5 titik integrasi API** yang berbeda:

| # | Integrasi | Pola |
|---|-----------|------|
| 1 | IoT Simulator → Backend | REST POST inbound |
| 2 | Backend → Webhook eksternal | REST POST outbound (event-driven) |
| 3 | Backend → Aplikasi penumpang | REST GET polling |
| 4 | Backend ↔ Dasbor operator | REST CRUD dengan otentikasi |
| 5 | Aplikasi penumpang → OpenStreetMap | Tile API pihak ketiga |

## Struktur Repository

```
crowdease/
├── README.md                  ← Dokumen ini
├── docker-compose.yml         ← Orkestrasi development
├── .env.example               ← Template konfigurasi
├── .gitignore
│
├── docs/                      ← Dokumentasi proyek
│   ├── DPPL_CrowdEase.docx    ← Dokumen perancangan formal
│   ├── API_CONTRACT.md        ← Kontrak API rinci
│   ├── ARCHITECTURE.md        ← Penjelasan arsitektur
│   └── images/                ← Diagram (akan dibuat)
│
├── backend/                   ← Aplikasi Laravel (akan diinstall)
│   └── README.md              ← Petunjuk setup Laravel
│
├── iot-simulator/             ← Python script simulator
│   ├── simulator.py
│   ├── requirements.txt
│   ├── .env.example
│   └── README.md
│
├── postman/                   ← API testing collection
│   └── README.md
│
└── scripts/                   ← Helper scripts
    ├── setup.sh               ← First-time setup
    └── README.md
```

## Quick Start

### Prasyarat

Pastikan terpasang di mesin development kalian:

- **PHP 8.2+** dengan ekstensi: `pdo_mysql`, `mbstring`, `xml`, `bcmath`, `zip`, `curl`
- **Composer 2.x**
- **MySQL 8** (atau pakai Docker)
- **Python 3.10+** untuk simulator
- **Node.js 18+** untuk asset compilation Laravel
- **Git**

### Setup Pertama Kali

```bash
# 1. Clone repository
git clone <repo-url> crowdease
cd crowdease

# 2. Jalankan setup script (Linux/macOS)
chmod +x scripts/setup.sh
./scripts/setup.sh

# Atau ikuti langkah manual:
# - cd backend && composer create-project laravel/laravel .
# - Konfigurasi .env, jalankan migrate + seed
# - cd ../iot-simulator && pip install -r requirements.txt
```

Detail lengkap ada di [`scripts/README.md`](scripts/README.md).

### Menjalankan untuk Development

Buka 3 terminal:

```bash
# Terminal 1: Database (Docker)
docker-compose up mysql

# Terminal 2: Backend Laravel
cd backend
php artisan serve

# Terminal 3: IoT Simulator
cd iot-simulator
python simulator.py
```

Lalu buka:
- **Aplikasi Penumpang**: http://localhost:8000
- **Dasbor Operator**: http://localhost:8000/admin (login: `operator@crowdease.test` / `secret123`)
- **API Documentation**: http://localhost:8000/docs

## Tech Stack

| Lapisan | Teknologi |
|---------|-----------|
| Backend | Laravel 11, PHP 8.2 |
| Database | MySQL 8 |
| Otentikasi | Laravel Sanctum (operator), API Key (IoT) |
| Frontend Templating | Blade + Tailwind CSS + Alpine.js |
| Visualisasi Peta | Leaflet.js + OpenStreetMap |
| Visualisasi Data | Chart.js |
| Simulator IoT | Python 3.10, requests, python-dotenv |
| Dokumentasi API | Scribe (auto-generate) |

## Roadmap

- [x] Dokumen Perancangan Perangkat Lunak (DPPL)
- [x] API Contract v1.0
- [x] IoT Simulator (Python)
- [x] Struktur repository
- [ ] **Minggu 1**: Migrasi database, seeder, autentikasi
- [ ] **Minggu 2**: Endpoint IoT (TI-1), endpoint admin (TI-4)
- [ ] **Minggu 3**: Frontend penumpang dengan polling, dasbor operator
- [ ] **Minggu 4**: Webhook outbound (TI-2), API versioning, dokumentasi, demo

## Tim

| Anggota | Peran | Tanggung Jawab |
|---------|-------|----------------|
| [Nama 1] | Backend Lead | API, database, auth, forecasting, webhook |
| [Nama 2] | Frontend Lead | Aplikasi penumpang, dasbor operator |
| [Nama 3] | Integration & QA | IoT simulator, dokumentasi, test cases |

## Lisensi & Atribusi

Proyek ini dikembangkan untuk tujuan akademis. Konsep dasar diadaptasi dari paper CrowdEase oleh tim MuridPakSoni Institut Teknologi Bandung (Best Paper GEMASTIK XVII Divisi Kota Cerdas, 2025).

## Tautan Dokumentasi

- [Dokumen Perancangan Perangkat Lunak](docs/DPPL_CrowdEase.docx) — formal SDD untuk submit
- [API Contract v1.0](docs/API_CONTRACT.md) — referensi endpoint
- [Arsitektur Sistem](docs/ARCHITECTURE.md) — penjelasan komponen
- [Backend Setup](backend/README.md) — petunjuk install Laravel
- [IoT Simulator](iot-simulator/README.md) — cara pakai simulator
