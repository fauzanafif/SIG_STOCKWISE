# STOCKWISE

Inventory Intelligence System untuk PT Surya Inti Gas — versi web produksi.

Monorepo:

| Folder | Isi |
|--------|-----|
| [`backend/`](backend/) | REST API — Laravel + PHP + MySQL + Sanctum |
| [`frontend/`](frontend/) | SPA — React + Vite + TypeScript + Tailwind + shadcn/ui |
| [`docs/`](docs/) | Analisis Excel, ERD, status flow, RBAC, API spec, calculation engine, test plan |

## Status pembangunan

Dibangun bertahap (lihat `docs/`). Fase berjalan: **PHASE 4 — Request**.

| Phase | Status |
|-------|--------|
| 0 — Analysis (Excel mapping, ERD, desain) | ✅ `docs/` |
| 1 — Project Setup (Laravel + React + MySQL + Sanctum, jalan lokal) | ✅ `docs/phase-1-report.md` |
| 2 — Auth + RBAC (+ import divisi & karyawan dari Excel) | ✅ `docs/phase-2-report.md` |
| 3 — Master Data + Inventory + Calculation Engine (+ import 8.957 barang dari Excel) | ✅ `docs/phase-3-report.md` |
| 4 — Request · 5 — NPBG + Pickup · 6 — Stock Opname · 7 — PPB + Purchasing | ⬜ |
| 8 — Tracking · 9 — Dashboard · 10 — Report + Export | ⬜ |

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
php artisan migrate --seed       # RBAC + 11 akun dev + import divisi/karyawan dari Excel
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

Dibuat oleh seeder — lihat `backend/database/seeders/`. Password dev: `password` (bukan untuk produksi).

| Role | username |
|------|----------|
| Super Admin | `superadmin` |
| Admin Gudang | `admingudang` |
| Anak Gudang | `anakgudang1`, `anakgudang2` |
| Lapangan Gudang | `lapangan1`, `lapangan2` |
| Purchasing | `purchasing` |
| BOS | `bos` |
| Karyawan | `karyawan1`, `karyawan2` |
| Karyawan (site BPN) | `karyawanbpn` |

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
