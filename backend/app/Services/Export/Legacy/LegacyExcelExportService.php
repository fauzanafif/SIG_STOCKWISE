<?php

namespace App\Services\Export\Legacy;

use App\Models\BorrowTransaction;
use App\Models\Item;
use App\Models\LendTransaction;
use App\Models\MaintenanceOrderSub;
use App\Models\ManufacturingOrderSub;
use App\Models\Npbg;
use App\Models\Ppb;
use App\Models\PpbAmendment;
use App\Models\Receiving;
use App\Models\StppTransaction;
use App\Models\TyreChange;
use App\Models\UsedReturn;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Replika 1:1 dari 9 file Excel lama PT Surya Inti Gas (docs/excel-data-mapping.md) —
 * sheet, kolom, dan urutan sama persis; SAFETY STOCK * memakai formula Excel asli
 * (bukan angka jadi) sehingga tetap bisa dihitung ulang bila dibuka di Excel.
 * Data diambil live dari database STOCKWISE — inilah "Excel" yang menggantikan file manual.
 */
class LegacyExcelExportService
{
    /** nama sheet legacy => Kategori Induk (docs/excel-data-mapping.md katalog enum) */
    private const CATEGORY_SHEETS = [
        'SAFETY STOCK ASSETS' => 'Assets',
        'SAFETY STOCK AUTOMOTIVE' => 'Automotive',
        'SAFETY STOCK BIG SPAREPARTS' => 'Big Spare Parts',
        'SAFETY STOCK ELECTRONICS & ELEC' => 'Electronics & Electricals',
        'SAFETY STOCK ETALASE' => 'Etalase',
        'SAFETY STOCK HOUSE HOLD & NEEDS' => 'Household Needs',
        'SAFETY STOCK MAINTENANCE & INDU' => 'Maintenance & Industry',
        'SAFETY STOCK MANUFAKTUR & ASSEM' => 'Manufacture & Assembly',
        'SAFETY STOCK OFFICE APPAREL & A' => 'Office Apparel & Accessories',
        'SAFETY STOCK OFFICE NEEDS' => 'Office Needs',
        'SAFETY STOCK POST USE ITEM' => 'Post-Use Items',
        'SAFETY STOCK SMALL SPAREPARTS' => 'Small Spare Parts',
    ];

    /** kode used_return_component_types => label kolom matriks legacy (urutan asli) */
    private const COMPONENT_LABELS = [
        'BONIT_BR' => 'Bonit BR', 'PEN_BR' => 'Pen BR', 'PEN_SS' => 'Pen SS',
        'VALVE_BR' => 'Valve BR', 'CYL_CAP' => 'Cyl Cap',
        'MUR_BR' => 'Mur BR', 'MUR_CS' => 'Mur CS', 'MUR_GI' => 'Mur GI', 'MUR_SS' => 'Mur SS',
        'PER_CS' => 'Per CS',
        'BAUT_BR' => 'Baut BR', 'BAUT_CS' => 'Baut CS', 'BAUT_GI' => 'Baut GI', 'BAUT_SS' => 'Baut SS',
    ];

    private const BULAN = ['Agt', 'Sept', 'Okt', 'Nov', 'Des', 'Jan', 'Feb', 'Mar', 'April', 'Mei', 'Juni', 'Juli'];

    // ================================================================== DATA.xlsx

    public function dataMaster(): Spreadsheet
    {
        $book = new Spreadsheet;
        $book->removeSheetByIndex(0);

        $this->buildDatabaseUtama($book);
        foreach (self::CATEGORY_SHEETS as $sheetName => $categoryName) {
            $this->buildSafetyStockSheet($book, $sheetName, $categoryName);
        }

        $book->setActiveSheetIndex(0);

        return $book;
    }

    private function buildDatabaseUtama(Spreadsheet $book): void
    {
        $sheet = $book->createSheet();
        $sheet->setTitle('DATABASE UTAMA');

        $headers = [
            'Kode Barang', 'Kategori Induk', 'Kategori Anak 1', 'Kategori Anak 2', 'Kategori Anak 3',
            'Deskripsi Barang', 'UoM', 'Perlu Blueprint?', 'Nama Alias', 'LETAK GUDANG', 'LETAK RAK',
            'BLUEPRINT IMG', 'BLUEPRINT DETAIL PDF', 'BLUEPRINT 3D VIEW', 'SISA STOK', 'LEAD TIME',
            '√LT', 'SAFETY STOCK', 'MIN PR',
        ];
        $this->writeHeaderRow($sheet, 1, $headers);
        $sheet->freezePane('A2');

        $row = 2;
        Item::query()->where('is_active', true)
            ->with(['category', 'unit:id,code', 'defaultWarehouse:id,code', 'defaultLocation:id,code', 'effectiveSafetyStock', 'inventory'])
            ->orderBy('code')
            ->chunk(500, function (Collection $items) use ($sheet, &$row) {
                foreach ($items as $item) {
                    $cat = array_pad(explode(' > ', (string) $item->category?->path), 4, '');
                    $actual = $item->inventory->sum('actual_qty');
                    $stockKnown = $item->inventory->contains(fn ($i) => $i->stock_known);
                    $ss = $item->effectiveSafetyStock;

                    $sheet->fromArray([
                        $item->code, $cat[0], $cat[1], $cat[2], $cat[3],
                        $item->description, $item->unit?->code, $item->needs_blueprint ? 'Ya' : 'Tidak', 'Tidak',
                        $item->defaultWarehouse?->code, $item->defaultLocation?->code,
                        '', '', $item->blueprint_3d_ref,
                        $stockKnown ? 'STOK '.rtrim(rtrim(number_format($actual, 2, '.', ''), '0'), '.').' '.$item->unit?->code : '',
                        $item->lead_time_days,
                        $ss?->sqrt_lt, $ss?->safety_stock, $ss?->min_pr,
                    ], null, "A{$row}");
                    $row++;
                }
            });

        // ribuan baris — lebar tetap, bukan autosize (mahal & berat memori di skala ini)
        $this->fixedWidths($sheet, [14, 16, 22, 22, 22, 42, 8, 12, 10, 14, 10, 12, 16, 16, 16, 10, 8, 12, 10]);
    }

    private function buildSafetyStockSheet(Spreadsheet $book, string $sheetName, string $categoryName): void
    {
        $sheet = $book->createSheet();
        $sheet->setTitle($sheetName);

        // baris judul 2-lapis persis file asli
        $sheet->setCellValue('A3', 'NO');
        $sheet->setCellValue('B3', 'ITEM DESCRIPTION');
        $sheet->mergeCells('C3:N3');
        $sheet->setCellValue('C3', 'NPBG (12 bulan terakhir)');
        $sheet->mergeCells('O3:R3');
        $sheet->setCellValue('O3', 'RATA RATA PENGELUARAN');
        $sheet->setCellValue('S3', 'LT');
        $sheet->setCellValue('T3', '√LT');
        $sheet->setCellValue('U3', 'SS');
        $sheet->setCellValue('V3', 'MIN PR');
        foreach (self::BULAN as $i => $bulan) {
            $sheet->setCellValue([3 + $i, 4], $bulan);
        }
        $sheet->setCellValue('O4', '1 BLN');
        $sheet->setCellValue('P4', '3 BLN');
        $sheet->setCellValue('Q4', '6 BLN');
        $sheet->setCellValue('R4', '12 BLN');
        $this->styleHeaderRow($sheet, 3, 22);
        $this->styleHeaderRow($sheet, 4, 22);
        $sheet->freezePane('C5');

        $items = Item::query()->where('is_active', true)
            ->whereHas('category', fn ($q) => $q->where('path', $categoryName)->orWhere('path', 'like', $categoryName.' > %'))
            ->with('unit:id,code')
            ->orderBy('description')
            ->get();

        // pemakaian bulanan 12 bulan terakhir dari movement STOCK_OUT (pengganti NPBG manual)
        $since = now()->subMonths(11)->startOfMonth();
        $monthly = DB::table('stock_movements')
            ->select('item_id', 'qty', 'created_at')
            ->where('movement_type', 'STOCK_OUT')
            ->where('created_at', '>=', $since)
            ->whereIn('item_id', $items->pluck('id'))
            ->get()
            ->groupBy('item_id')
            ->map(function ($rows) use ($since) {
                $byMonth = array_fill(0, 12, 0.0);
                foreach ($rows as $r) {
                    $idx = (int) $since->diffInMonths(Carbon::parse($r->created_at)->startOfMonth());
                    if ($idx >= 0 && $idx < 12) {
                        $byMonth[$idx] += (float) $r->qty;
                    }
                }

                return $byMonth;
            });

        $row = 5;
        $no = 1;
        foreach ($items as $item) {
            $months = $monthly->get($item->id, array_fill(0, 12, 0.0));
            $sheet->setCellValue("A{$row}", $no++);
            $sheet->setCellValue("B{$row}", $item->description);
            foreach ($months as $i => $qty) {
                $sheet->setCellValue([3 + $i, $row], $qty);
            }
            // formula ASLI dari DATA.xlsx (dikonfirmasi lewat inspeksi file, bukan rekaan)
            $sheet->setCellValue("O{$row}", "=SUM(C{$row}:N{$row})*1/12");
            $sheet->setCellValue("P{$row}", "=SUM(D{$row}:N{$row})*3/12");
            $sheet->setCellValue("Q{$row}", "=SUM(E{$row}:N{$row})*6/12");
            $sheet->setCellValue("R{$row}", "=SUM(F{$row}:N{$row})*12/12");
            $sheet->setCellValue("S{$row}", $item->lead_time_days ?: 0);
            $sheet->setCellValue("T{$row}", "=SQRT(S{$row}/30)");
            $sheet->setCellValue("U{$row}", "=ROUNDUP((2.33*O{$row})*T{$row},0)");
            $sheet->setCellValue("V{$row}", "=ROUNDUP((O{$row}*S{$row}/30)+1,0)");
            $row++;
        }

        $this->fixedWidths($sheet, array_merge([5, 42], array_fill(0, 12, 6), [8, 8, 8, 8, 6, 8, 6, 8]));
    }

    // ================================================================== 1. PPB - RI.xlsx

    public function ppbRi(): Spreadsheet
    {
        $book = new Spreadsheet;
        $book->removeSheetByIndex(0);

        $sheet = $book->createSheet();
        $sheet->setTitle('PPB');
        $this->writeHeaderRow($sheet, 1, ['Tgl PPB', 'No PPB', 'Deskripsi Barang', 'Kuantitas', 'Satuan', 'Peminta', 'Divisi', 'Keterangan', 'Status']);
        $sheet->freezePane('A2');
        $row = 2;
        Ppb::query()->with(['items.item', 'items.unit', 'requester', 'department'])->orderBy('date')
            ->chunk(200, function ($ppbs) use ($sheet, &$row) {
                foreach ($ppbs as $ppb) {
                    foreach ($ppb->items as $line) {
                        $sheet->fromArray([
                            $ppb->date?->toDateString(), $ppb->number,
                            $line->item?->description ?? $line->description_raw, $line->qty, $line->unit?->code,
                            $ppb->requester?->name, $ppb->department?->name, $ppb->notes,
                            $this->legacyPpbStatus($ppb->status),
                        ], null, "A{$row}");
                        $row++;
                    }
                }
            });
        $this->autosize($sheet, 9);

        $sheet2 = $book->createSheet();
        $sheet2->setTitle('RI');
        $this->writeHeaderRow($sheet2, 1, ['Tgl RI', 'No RI', 'Deskripsi Barang', 'Kuantitas', 'Satuan', 'No PPB', 'No PO', 'Vendor', 'No Surat Jalan', 'Pemeriksa', 'Keterangan']);
        $sheet2->freezePane('A2');
        $row = 2;
        Receiving::query()->with(['items.item', 'items.unit', 'ppb', 'purchaseOrder', 'vendor', 'checkedBy'])->orderBy('date')
            ->chunk(200, function ($ris) use ($sheet2, &$row) {
                foreach ($ris as $ri) {
                    foreach ($ri->items as $line) {
                        $sheet2->fromArray([
                            $ri->date?->toDateString(), $ri->number,
                            $line->item?->description ?? $line->description_raw, $line->qty_received, $line->unit?->code,
                            $ri->ppb?->number, $ri->purchaseOrder?->number, $ri->vendor?->name,
                            $ri->surat_jalan_no, $ri->checkedBy?->name,
                            $line->condition_note ?? $ri->notes,
                        ], null, "A{$row}");
                        $row++;
                    }
                }
            });
        $this->autosize($sheet2, 11);

        $sheet3 = $book->createSheet();
        $sheet3->setTitle('PPB Perubahan');
        $this->writeHeaderRow($sheet3, 1, ['Tgl Perubahan', 'No PPB', 'Deskripsi Barang', 'Kuantitas', 'Satuan', 'Peminta', 'Divisi', 'Tipe Perubahan', 'Keterangan']);
        $sheet3->freezePane('A2');
        $row = 2;
        PpbAmendment::query()->with(['ppb.requester', 'ppb.department', 'ppbItem.item', 'ppbItem.unit'])->orderBy('date')
            ->chunk(200, function ($rows) use ($sheet3, &$row) {
                foreach ($rows as $a) {
                    $sheet3->fromArray([
                        $a->date?->toDateString(), $a->ppb?->number,
                        $a->ppbItem?->item?->description ?? $a->ppbItem?->description_raw, $a->qty_after ?? $a->qty_before,
                        $a->ppbItem?->unit?->code, $a->ppb?->requester?->name, $a->ppb?->department?->name,
                        $a->type, $a->reason,
                    ], null, "A{$row}");
                    $row++;
                }
            });
        $this->autosize($sheet3, 9);

        $book->setActiveSheetIndex(0);

        return $book;
    }

    private function legacyPpbStatus(string $status): string
    {
        return match ($status) {
            'DRAFT', 'SUBMITTED', 'REVIEW', 'APPROVED', 'PURCHASING', 'ORDERED' => 'Requested',
            'PARTIAL_RECEIVED' => 'Shortage',
            'RECEIVED', 'COMPLETED' => 'Completed',
            'CANCELLED' => 'Close',
            default => $status,
        };
    }

    // ================================================================== 2. NPBG.xlsx

    public function npbg(): Spreadsheet
    {
        $book = new Spreadsheet;
        $book->removeSheetByIndex(0);
        $sheet = $book->createSheet();
        $sheet->setTitle('NPBG');
        $this->writeHeaderRow($sheet, 1, [
            'Tgl NPBG', 'No NPBG', 'Tipe NPBG', 'Klasifikasi', 'Pelanggan', 'Nama Proyek', 'No Seri / Nopol',
            'Deskripsi Barang', 'Kuantitas', 'Satuan', 'Peminta', 'Dikeluarkan Oleh', 'Divisi', 'Keterangan',
        ]);
        $sheet->freezePane('A2');
        $row = 2;
        Npbg::query()->with(['items.item', 'items.unit', 'requester', 'issuedBy', 'department', 'customer', 'project', 'asset'])
            ->orderBy('date')
            ->chunk(200, function ($npbgs) use ($sheet, &$row) {
                foreach ($npbgs as $n) {
                    foreach ($n->items as $line) {
                        $sheet->fromArray([
                            $n->date?->toDateString(), $n->number,
                            $n->type === 'PENJUALAN' ? 'PENJUALAN' : 'NON-PENJUALAN', $n->classification,
                            $n->customer?->name, $n->project?->name,
                            $n->asset?->code ?? $n->asset_ref,
                            $line->item?->description ?? $line->description_raw, $line->qty, $line->unit?->code,
                            $n->requester?->name, $n->issuedBy?->name, $n->department?->name, $n->notes,
                        ], null, "A{$row}");
                        $row++;
                    }
                }
            });
        $this->autosize($sheet, 14);
        $book->setActiveSheetIndex(0);

        return $book;
    }

    // ================================================================== 3. Tracking Borrow & Lend.xlsx

    public function borrowLend(): Spreadsheet
    {
        $book = new Spreadsheet;
        $book->removeSheetByIndex(0);

        $sheet = $book->createSheet();
        $sheet->setTitle('Lend');
        $this->writeHeaderRow($sheet, 1, [
            'Tgl Pinjam', 'Deskripsi Barang', 'Kuantitas', 'Satuan', 'Peminta', 'Keperluan', 'Est. Pinjam (hari)',
            'Tanda Keluar', 'Keterangan Keluar', 'Status', 'Tanda Kembali', 'Tgl Kembali', 'Keterangan Kembali',
        ]);
        $sheet->freezePane('A2');
        $row = 2;
        LendTransaction::query()->with(['item', 'unit', 'outNpbg', 'returnRi'])->orderBy('out_date')
            ->chunk(200, function ($rows) use ($sheet, &$row) {
                foreach ($rows as $l) {
                    $sheet->fromArray([
                        $l->out_date?->toDateString(), $l->item?->description ?? $l->description_raw, $l->qty, $l->unit?->code,
                        $l->borrower_name, $l->purpose, $l->est_days,
                        $l->outNpbg?->number, $l->condition_out,
                        match ($l->status) {
                            'RETURNED' => 'KEMBALI', 'OVERDUE' => 'DEADLINE', default => 'SEDANG DIPINJAM'
                        },
                        $l->returnRi?->number, $l->return_date?->toDateString(), $l->condition_in,
                    ], null, "A{$row}");
                    $row++;
                }
            });
        $this->autosize($sheet, 13);

        $sheet2 = $book->createSheet();
        $sheet2->setTitle('Borrow');
        $this->writeHeaderRow($sheet2, 1, [
            'Tgl Pinjam', 'Deskripsi Barang', 'Kuantitas', 'Satuan', 'Vendor', 'Keterangan',
            'Tanda Terima', 'Status', 'Tanda Keluar', 'Keterangan Barang Kembali',
        ]);
        $sheet2->freezePane('A2');
        $row = 2;
        BorrowTransaction::query()->with(['item', 'unit', 'lenderVendor', 'returnNpbg'])->orderBy('borrowed_at')
            ->chunk(200, function ($rows) use ($sheet2, &$row) {
                foreach ($rows as $b) {
                    $sheet2->fromArray([
                        $b->borrowed_at?->toDateString(), $b->item?->description ?? $b->description_raw, $b->qty, $b->unit?->code,
                        $b->lenderVendor?->name ?? $b->lender_name, null,
                        $b->receipt_ref, $b->status === 'RETURNED' ? 'LUNAS' : 'SEDANG DIPINJAM',
                        $b->returnNpbg?->number, $b->condition_note,
                    ], null, "A{$row}");
                    $row++;
                }
            });
        $this->autosize($sheet2, 10);

        $book->setActiveSheetIndex(0);

        return $book;
    }

    // ================================================================== 4. Tracking STPP.xlsx

    public function stpp(): Spreadsheet
    {
        $book = new Spreadsheet;
        $book->removeSheetByIndex(0);
        $sheet = $book->createSheet();
        $sheet->setTitle('STPP');
        $this->writeHeaderRow($sheet, 1, [
            'No', 'No Seri', 'Deskripsi Barang', 'Kuantitas', 'Satuan', 'Peminta', 'Penempatan',
            'Tgl NPBG', 'No. NPBG', 'Keterangan Keluar', 'Status', 'Tgl RI', 'Tanda Kembali', 'Keterangan Kembali',
        ]);
        $sheet->freezePane('A2');
        $row = 2;
        $no = 1;
        StppTransaction::query()->with(['item', 'unit', 'outNpbg', 'returnRi'])->orderBy('out_date')
            ->chunk(200, function ($rows) use ($sheet, &$row, &$no) {
                foreach ($rows as $s) {
                    $sheet->fromArray([
                        $no++, $s->serial_no_raw, $s->item?->description ?? $s->description_raw, $s->qty, $s->unit?->code,
                        $s->holder_name_raw, $s->placement_raw,
                        $s->out_date?->toDateString(), $s->outNpbg?->number, $s->out_note,
                        $s->status, $s->return_date?->toDateString(), $s->returnRi?->number, $s->return_note,
                    ], null, "A{$row}");
                    $row++;
                }
            });
        $this->autosize($sheet, 14);
        $book->setActiveSheetIndex(0);

        return $book;
    }

    // ================================================================== 5. Tracking Ban Luar.xlsx

    public function banLuar(): Spreadsheet
    {
        $book = new Spreadsheet;
        $book->removeSheetByIndex(0);
        $sheet = $book->createSheet();
        $sheet->setTitle('Ban Luar');
        $this->writeHeaderRow($sheet, 1, [
            'Nopol (Kendaraan)', 'Tgl NPBG', 'No NPBG', 'Deskripsi Ban Baru', 'No Seri Baru', 'Ban', 'Pergantian',
            'Keterangan Keluar', 'Status', 'Tgl RI', 'No. RI', 'Deskripsi Ban Lama', 'No Seri Lama', 'Keterangan Kembali',
        ]);
        $sheet->freezePane('A2');
        $row = 2;
        TyreChange::query()->with(['asset', 'outNpbg', 'inRi'])->orderBy('change_date')
            ->chunk(200, function ($rows) use ($sheet, &$row) {
                foreach ($rows as $t) {
                    $sheet->fromArray([
                        $t->asset ? "{$t->asset->code} ({$t->asset->brand_model})" : null,
                        $t->change_date?->toDateString(), $t->outNpbg?->number,
                        $t->new_tyre_desc, $t->new_serial_raw, $t->position, $t->change_seq,
                        $t->reason, $t->status === 'PENDING_RI' ? 'PENDING RI' : 'CLEAR',
                        $t->in_date?->toDateString(), $t->inRi?->number,
                        $t->old_tyre_desc, $t->old_serial_raw, $t->is_opening ? 'PENDATAAN AWAL' : null,
                    ], null, "A{$row}");
                    $row++;
                }
            });
        $this->autosize($sheet, 14);
        $book->setActiveSheetIndex(0);

        return $book;
    }

    // ================================================================== 6. Tracking Maintenance Assets.xlsx

    public function maintenanceAssets(): Spreadsheet
    {
        $book = new Spreadsheet;
        $book->removeSheetByIndex(0);
        $sheet = $book->createSheet();
        $sheet->setTitle('Maintenance Kendaraan');
        $this->writeHeaderRow($sheet, 1, [
            'Tgl Laporan', 'No. SPK', 'Sub SPK', 'Nopol (Kendaraan)', 'Keterangan Awal', 'Bengkel',
            'Status Hasil Pengerjaan', 'No. NPBG', 'Tgl Selesai Pengerjaan', 'Keterangan Akhir',
        ]);
        $sheet->freezePane('A2');
        $row = 2;
        MaintenanceOrderSub::query()->with(['order.asset', 'workshop', 'npbg:id,number'])
            ->join('maintenance_orders', 'maintenance_orders.id', '=', 'maintenance_order_subs.maintenance_order_id')
            ->orderBy('maintenance_orders.report_date')->orderBy('maintenance_order_subs.sub_no')
            ->select('maintenance_order_subs.*')
            ->chunk(200, function ($subs) use ($sheet, &$row) {
                foreach ($subs as $sub) {
                    $order = $sub->order;
                    $sheet->fromArray([
                        $order->report_date?->toDateString(), $order->number, $sub->sub_no,
                        $order->asset ? "{$order->asset->code} ({$order->asset->brand_model})" : null,
                        $sub->problem_detail ?? $order->problem_summary,
                        $sub->workshop?->name ?? $sub->workshop_raw,
                        $sub->status === 'COMPLETED' ? 'COMPLETED' : 'ON-GOING',
                        $sub->npbg_id ? $sub->npbg?->number : null,
                        $sub->finish_date?->toDateString(), $sub->result_note,
                    ], null, "A{$row}");
                    $row++;
                }
            });
        $this->autosize($sheet, 10);
        $book->setActiveSheetIndex(0);

        return $book;
    }

    // ================================================================== 7. Tracking Manufaktur & Assembly.xlsx

    public function manufakturAssembly(): Spreadsheet
    {
        $book = new Spreadsheet;
        $book->removeSheetByIndex(0);

        $headers = [
            'Tanggal', 'No. Manufaktur', 'Sub', 'Lokasi', 'Hasil Produk', 'No. Seri', 'Item No', 'Proses',
            'Keterangan Awal', 'Status Hasil Pengerjaan', 'No. NPBG', 'Tgl Selesai Pengerjaan', 'No. RI', 'Keterangan Akhir',
        ];

        $sheet = $book->createSheet();
        $sheet->setTitle('Manufaktur & Assembly');
        $this->writeHeaderRow($sheet, 1, $headers);
        $sheet->freezePane('A2');
        $this->fillManufacturingRows($sheet, 'ASSEMBLY', 'SIG');
        $this->autosize($sheet, 14);

        $sheet2 = $book->createSheet();
        $sheet2->setTitle('Manufaktur & Jasa Lain-Lain');
        $this->writeHeaderRow($sheet2, 1, $headers);
        $sheet2->freezePane('A2');
        $this->fillManufacturingRows($sheet2, 'JASA', null);
        $this->autosize($sheet2, 14);

        $book->setActiveSheetIndex(0);

        return $book;
    }

    private function fillManufacturingRows(Worksheet $sheet, string $kind, ?string $fixedLokasi): void
    {
        $row = 2;
        ManufacturingOrderSub::query()->with(['order.vendor', 'npbg:id,number', 'ri:id,number'])
            ->whereHas('order', fn ($q) => $q->where('kind', $kind))
            ->join('manufacturing_orders', 'manufacturing_orders.id', '=', 'manufacturing_order_subs.manufacturing_order_id')
            ->orderBy('manufacturing_orders.date')->orderBy('manufacturing_order_subs.sub_no')
            ->select('manufacturing_order_subs.*')
            ->chunk(200, function ($subs) use ($sheet, &$row, $fixedLokasi) {
                foreach ($subs as $sub) {
                    $order = $sub->order;
                    $sheet->fromArray([
                        $order->date?->toDateString(), $order->number, $sub->sub_no,
                        $fixedLokasi ?? $order->vendor?->name, $order->product_name,
                        $sub->serial_no_raw, $sub->item_no, $sub->process,
                        $sub->note_start, $sub->status === 'COMPLETED' ? 'COMPLETED' : 'ON-GOING',
                        $sub->npbg_id ? $sub->npbg?->number : null,
                        $sub->finish_date?->toDateString(), $sub->ri_id ? $sub->ri?->number : null,
                        $sub->note_end,
                    ], null, "A{$row}");
                    $row++;
                }
            });
    }

    // ================================================================== 8. Tracking Pengembalian Bekas.xlsx

    public function pengembalianBekas(): Spreadsheet
    {
        $book = new Spreadsheet;
        $book->removeSheetByIndex(0);

        $sheet = $book->createSheet();
        $sheet->setTitle('Spare Part');
        $componentLabels = array_values(self::COMPONENT_LABELS);
        $this->writeHeaderRow($sheet, 1, array_merge(['No', 'Tgl NPBG', 'No NPBG', 'Status', 'Tgl RI', 'No RI'], $componentLabels, ['Keterangan']));
        $sheet->freezePane('A2');
        $row = 2;
        $no = 1;
        UsedReturn::query()->where('format', 'COMPONENT_MATRIX')->with(['items.componentType', 'npbg', 'ri'])
            ->orderBy('return_date')
            ->chunk(100, function ($urs) use ($sheet, &$row, &$no) {
                foreach ($urs as $ur) {
                    $byCode = $ur->items->keyBy(fn ($i) => $i->componentType?->code);
                    $vals = ['No' => $no++, 'Tgl NPBG' => $ur->return_date?->toDateString(), 'No NPBG' => $ur->npbg?->number ?? $ur->npbg_ref_raw,
                        'Status' => $ur->status, 'Tgl RI' => $ur->return_date?->toDateString(), 'No RI' => $ur->ri?->number];
                    $line = array_values($vals);
                    foreach (array_keys(self::COMPONENT_LABELS) as $code) {
                        $line[] = $byCode[$code]->qty ?? null;
                    }
                    $line[] = $ur->note;
                    $sheet->fromArray($line, null, "A{$row}");
                    $row++;
                }
            });
        $this->autosize($sheet, 6 + count($componentLabels) + 1);

        $sheet2 = $book->createSheet();
        $sheet2->setTitle('Spare Part Lain');
        $this->writeHeaderRow($sheet2, 1, ['Tgl NPBG', 'No NPBG', 'Deskripsi Barang', 'Kuantitas', 'Satuan', 'Item No', 'Status', 'No RI', 'Keterangan']);
        $sheet2->freezePane('A2');
        $row = 2;
        UsedReturn::query()->where('format', 'ITEM_LINE')->with(['items.item', 'items.unit', 'npbg', 'ri'])
            ->orderBy('return_date')
            ->chunk(100, function ($urs) use ($sheet2, &$row) {
                foreach ($urs as $ur) {
                    foreach ($ur->items as $line) {
                        $prefix = match ($line->condition) {
                            'SCRAP' => '(BUANG) ', 'DAMAGED' => '(RUSAK) ', default => '(BEKAS) ',
                        };
                        $sheet2->fromArray([
                            $ur->return_date?->toDateString(), $ur->npbg?->number ?? $ur->npbg_ref_raw,
                            $prefix.($line->item?->description ?? $line->description_raw),
                            $line->qty, $line->unit?->code, $line->item_no,
                            $ur->status, $ur->ri?->number, $ur->note ?? $line->note,
                        ], null, "A{$row}");
                        $row++;
                    }
                }
            });
        $this->autosize($sheet2, 9);

        $book->setActiveSheetIndex(0);

        return $book;
    }

    // ================================================================== helpers

    private function writeHeaderRow(Worksheet $sheet, int $row, array $headers): void
    {
        foreach ($headers as $i => $h) {
            $sheet->setCellValue([$i + 1, $row], $h);
        }
        $this->styleHeaderRow($sheet, $row, count($headers));
    }

    private function styleHeaderRow(Worksheet $sheet, int $row, int $cols): void
    {
        $style = $sheet->getStyle([1, $row, $cols, $row]);
        $style->getFont()->setBold(true);
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E7ECF2');
        $style->getAlignment()->setWrapText(true)->setVertical('center');
    }

    private function autosize(Worksheet $sheet, int $cols): void
    {
        for ($c = 1; $c <= $cols; $c++) {
            $sheet->getColumnDimensionByColumn($c)->setAutoSize(true);
        }
    }

    /** @param  list<int>  $widths  lebar per kolom — dipakai untuk sheet ribuan baris (autosize terlalu berat). */
    private function fixedWidths(Worksheet $sheet, array $widths): void
    {
        foreach ($widths as $i => $w) {
            $sheet->getColumnDimensionByColumn($i + 1)->setWidth($w);
        }
    }
}
