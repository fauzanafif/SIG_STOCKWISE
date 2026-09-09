# STOCKWISE

Inventory Intelligence System untuk PT Surya Inti Gas — versi web produksi.

Monorepo:

| Folder | Isi |
|--------|-----|
| [`backend/`](backend/) | REST API — Laravel + PHP + MySQL + Sanctum |
| [`frontend/`](frontend/) | SPA — React + Vite + TypeScript + Tailwind + shadcn/ui |
| [`docs/`](docs/) | Analisis Excel, ERD, status flow, RBAC, API spec, calculation engine, test plan |

## Status pembangunan

Dibangun bertahap (lihat `docs/`). Status: **10 fase selesai** — siap uji pakai.

| Phase | Status |
|-------|--------|
| 0 — Analysis (Excel mapping, ERD, desain) | ✅ `docs/` |
| 1 — Project Setup (Laravel + React + MySQL + Sanctum, jalan lokal) | ✅ `docs/phase-1-report.md` |
| 2 — Auth + RBAC (+ import divisi & karyawan dari Excel) | ✅ `docs/phase-2-report.md` |
| 3 — Master Data + Inventory + Calculation Engine (+ import 8.957 barang dari Excel) | ✅ `docs/phase-3-report.md` |
| 4 — Request (buat → review + cek fisik → reserve) | ✅ `docs/phase-4-report.md` |
| 5 — NPBG + Pickup (stok turun saat pickup) | ✅ `docs/phase-5-report.md` |
| 6 — Stock Opname (selisih → adjustment saat approve) | ✅ `docs/phase-6-report.md` |
| 7 — PPB + Purchasing (PPB → PO → Receiving → stok naik saat confirm) | ✅ `docs/phase-7-report.md` |
| 8 — Tracking (Lend/Borrow/STPP/Ban Luar/Maintenance/Manufaktur/Bekas) | ✅ `docs/phase-8-report.md` |
| 9 — Dashboard per peran (KPI + chart) | ✅ `docs/phase-9-report.md` |
| 10 — Laporan & Export (Excel / CSV / PDF) | ✅ `docs/phase-10-report.md` |

> `migrate:fresh --seed` menjalankan import Excel penuh (~2–3 menit). Untuk reset cepat tanpa import:
> `php artisan migrate:fresh && php artisan db:seed --class=SiteSeeder && php artisan db:seed --class=RbacSeeder && php artisan db:seed --class=UserSeeder`

## Setup lokal

Prasyarat: PHP 8.3+, Composer 2, Node 20+, MySQL 8. (Mesin dev ini pakai Laragon.)

### Backend

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
# sesuaikan DB_* di .env (default: MySQL, db `stockwise`, root tanpa password), lalu:
php artisan migrate --seed       # RBAC + akun dev + import master dari Excel (~2-3 menit)
php artisan serve --port=8001    # http://127.0.0.1:8001
```

Import data Excel (folder default: root repo, atur `STOCKWISE_IMPORT_PATH` di `.env`):

```bash
php artisan stockwise:import --list      # importer yang tersedia
php artisan stockwise:import             # jalankan semua (idempoten, aman diulang)
php artisan stockwise:import departments # target tertentu
```

> Port **8001** dipakai karena :8000 sering ditempati project lain di mesin ini. Ubah di
> `.env` (`APP_URL`) + `frontend/vite.config.ts` (proxy target) bila ingin :8000.

### Frontend

```bash
cd frontend
npm install
cp .env.example .env         # biarkan VITE_API_URL kosong → pakai proxy Vite
npm run dev                   # http://127.0.0.1:5173
```

Frontend memanggil API lewat Axios. Saat dev, `VITE_API_URL` dibiarkan kosong sehingga request `/api`
di-proxy oleh Vite ke backend (`frontend/vite.config.ts`). Untuk staging/prod, isi `VITE_API_URL`
dengan URL backend.

### Akun uji (development)

Dibuat oleh `backend/database/seeders/UserSeeder.php`. Login pakai **username atau email**.
Password dev semua akun: **`Password@26`** (bukan untuk produksi).

| Role | username | email |
|------|----------|-------|
| Super Admin | `superadmin` | `superadmin@gmail.com` |
| Admin Gudang | `admingudang` | `admingudang@gmail.com` |
| Admin Lapangan (lapangan gudang) | `adminlapangan` | `adminlapangan@gmail.com` |
| Purchasing | `purchasing` | `purchasing@gmail.com` |
| BOS | `bos` | `bos@gmail.com` |
| Karyawan | `kariawan` | `kariawan@gmail.com` |
| Anak Gudang / Stock Opname | `anakgudang1`, `anakgudang2` | `anakgudang1@gmail.com`, … |
| Karyawan (site BPN) | `karyawanbpn` | `karyawanbpn@gmail.com` |

### Testing

Jalankan dari folder masing-masing (kalau shell tidak mendukung `&&`, jalankan `cd` terpisah).

**Backend** — pakai SQLite in-memory, **tidak butuh MySQL nyala**, ~5 detik:

```bash
cd backend
php artisan test                     # semua
php artisan test --filter=Auth       # satu grup
php artisan test tests/Unit          # satu folder
composer test:mysql                  # jalankan lagi di MySQL (butuh db stockwise_test + MySQL nyala)
vendor/bin/pint --test               # cek format (tanpa --test = auto-fix)
```

**Frontend** — butuh `npm install` sekali:

```bash
cd frontend
npm run test        # Vitest (unit/component)
npm run typecheck   # tsc -b
npm run lint        # oxlint
npm run build       # tsc + vite build -> dist/
```

**E2E (Playwright)** — butuh MySQL nyala + backend & seed siap:

```bash
# sekali: install browser
cd frontend && npx playwright install chromium

# terminal 1 — backend ter-seed
cd backend
php artisan migrate:fresh --seed
php artisan serve --port=8001

# terminal 2 — Playwright akan otomatis menjalankan `npm run dev`
cd frontend
npm run e2e                       # semua
npm run e2e -- auth.spec.ts       # satu file
npx playwright test --ui          # mode interaktif
```

### Reset DB (development saja)

```bash
cd backend && php artisan migrate:fresh --seed
```

## Git

Branch: `main` (rilis), `develop` (integrasi), `feature/*` (pengembangan).
Jangan `git push --force` tanpa diminta.
