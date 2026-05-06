# Dokumentasi CrowdEase

Folder ini berisi semua dokumen yang perlu disubmit dan diserahkan untuk mata kuliah, plus dokumen referensi internal tim.

## Dokumen Utama (untuk submit)

| Dokumen | Format | Deskripsi |
|---------|--------|-----------|
| [DPPL_CrowdEase.docx](DPPL_CrowdEase.docx) | Word | Dokumen Perancangan Perangkat Lunak — 8 BAB + Lampiran |
| [API_CONTRACT.md](API_CONTRACT.md) | Markdown | Kontrak API resmi v1.0 — semua endpoint dengan request/response |
| [ARCHITECTURE.md](ARCHITECTURE.md) | Markdown | Penjelasan arsitektur dan keputusan desain |

## Dokumen Pendukung

- `images/` — Folder untuk diagram visual yang dibuat dengan draw.io / Lucidchart
- (akan ditambah seiring berjalannya pengembangan)

## Diagram yang Perlu Dibuat

Daftar lengkap diagram yang harus dibuat tim dan disisipkan ke DPPL:

| Kode | Nama | Tipe | Tools yang Disarankan |
|------|------|------|----------------------|
| G2.1 | Konteks Sistem | Context Diagram | draw.io |
| G3.1 | Use Case Diagram | UML Use Case | draw.io / PlantUML |
| G4.1 | Arsitektur Sistem | Block Diagram | draw.io |
| G4.2 | Sequence Diagram Skenario A | UML Sequence | PlantUML / sequencediagram.org |
| G4.3 | Entity Relationship Diagram | ERD | draw.io / dbdiagram.io |
| G4.4 | Mockup Halaman Penumpang | UI Mockup | Figma / Balsamiq |
| G4.5 | Mockup Dasbor Operator | UI Mockup | Figma |
| G6.1 | Diagram Penyebaran | UML Deployment | draw.io |

Simpan semua diagram (PNG export) di `images/` dengan nama file yang match kode di tabel di atas, mis. `images/G4.1_arsitektur.png`.

## Saat Akan Submit

Pastikan beberapa hal sebelum dikumpulkan:

1. **DPPL.docx** — semua placeholder diagram sudah diganti dengan gambar asli
2. **DPPL.docx** — halaman cover sudah diisi nama anggota dan NIM
3. **API_CONTRACT.md** — referensi sudah final, tidak ada placeholder text
4. **README.md** repository — link ke video demo (kalau ada)
5. **Postman Collection** — sudah di-export dan disimpan di `../postman/`
6. Generate **PDF** dari DPPL untuk memastikan tata letak tidak rusak saat dibuka di komputer dosen

## Tips Penyusunan Laporan

- Tulis laporan utama (Bab 1-5 atau Pendahuluan-Penutup) dengan **mereferensikan** dokumen-dokumen di folder ini sebagai lampiran
- Misal di laporan: "Detail kontrak API tersedia pada Lampiran A (lihat dokumen `API_CONTRACT.md`)"
- Pendekatan ini lebih rapi daripada menyalin seluruh isi dokumen ke laporan
