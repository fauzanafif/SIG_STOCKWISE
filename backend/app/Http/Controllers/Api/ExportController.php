<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\Item;
use App\Models\MaterialRequest;
use App\Models\Npbg;
use App\Models\Ppb;
use App\Models\StockMovement;
use App\Models\StockOpname;
use App\Services\Export\DatasetExporter;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * PHASE 10 — laporan & export (Excel / CSV / PDF-print). brief §AG.
 * GET /api/export/{dataset}?format=xlsx|csv|pdf
 */
class ExportController extends Controller
{
    private const DATASETS = [
        'items' => 'report.items',
        'inventory' => 'report.inventory',
        'requests' => 'report.request',
        'npbg' => 'report.npbg',
        'ppb' => 'report.ppb',
        'stock-opnames' => 'report.opname',
        'stock-movements' => 'report.stock_movement',
    ];

    public function __invoke(Request $request, string $dataset, DatasetExporter $exporter): StreamedResponse
    {
        abort_unless(isset(self::DATASETS[$dataset]), 404);
        abort_unless($request->user()->hasPermission(self::DATASETS[$dataset]), 403);

        $format = strtolower((string) $request->query('format', 'xlsx'));
        if (! in_array($format, ['xlsx', 'csv', 'pdf'], true)) {
            throw ValidationException::withMessages(['format' => ['Format harus xlsx, csv, atau pdf.']]);
        }
        abort_unless($request->user()->hasPermission("export.{$this->exportPerm($format)}"), 403);

        [$title, $headers, $rows] = $this->build($dataset, $request);
        $filename = 'stockwise-'.$dataset.'-'.now()->format('Ymd-His');

        return $exporter->download($format, $filename, $headers, $rows, $title);
    }

    private function exportPerm(string $format): string
    {
        return $format === 'pdf' ? 'pdf' : ($format === 'csv' ? 'csv' : 'excel');
    }

    /** @return array{0:string,1:list<string>,2:iterable} */
    private function build(string $dataset, Request $request): array
    {
        return match ($dataset) {
            // Mengikuti filter yang sedang aktif di halaman Master Barang
            // (search, kategori Accurate anak 1/2/3, UOM, dll) — lihat
            // Item::filtered(), sumber tunggal yang sama dipakai ItemController::index().
            'items' => [
                'Master Barang',
                [
                    'Kode Barang', 'Kategori Induk', 'Kategori Anak 1', 'Kategori Anak 2', 'Kategori Anak 3',
                    'Deskripsi Barang', 'UOM', 'QTY', 'Perlu Blueprint?', 'Nama Alias',
                    'Letak Gudang', 'Letak Rak', 'Blueprint IMG', 'Blueprint Detail PDF',
                    'Lead Time', 'Safety Stock', 'MIN PR',
                ],
                Item::filtered($request)
                    ->with('unit:id,code', 'defaultWarehouse:id,code', 'defaultLocation:id,code', 'effectiveSafetyStock:id,item_id,safety_stock,min_pr')
                    ->orderBy('items.code')
                    ->lazy()
                    ->map(fn (Item $i) => [
                        $i->code,
                        null, // Kategori Induk — selalu NULL, tidak ada sumbernya di Accurate (§12)
                        $i->accurate_category_anak_1, $i->accurate_category_anak_2, $i->accurate_category_anak_3,
                        $i->description, $i->unit?->code, $i->accurate_qty_onhand,
                        $i->needs_blueprint ? 'Ya' : 'Tidak', 'Tidak',
                        $i->defaultWarehouse?->code, $i->defaultLocation?->code,
                        $i->blueprint_img_path, $i->blueprint_pdf_path,
                        $i->lead_time_days, $i->effectiveSafetyStock?->safety_stock, $i->effectiveSafetyStock?->min_pr,
                    ]),
            ],
            'inventory' => [
                'Laporan Inventory',
                ['Kode', 'Deskripsi', 'Gudang', 'Aktual', 'Reserved', 'Tersedia', 'Stok Diketahui'],
                Inventory::query()->with('item:id,code,description', 'warehouse:id,code')
                    ->join('items', 'items.id', '=', 'inventory.item_id')->orderBy('items.code')
                    ->select('inventory.*')->lazy()->map(fn ($i) => [
                        $i->item?->code, $i->item?->description, $i->warehouse?->code,
                        $i->actual_qty, $i->reserved_qty, $i->available_qty, $i->stock_known ? 'Ya' : 'Tidak',
                    ]),
            ],
            'requests' => [
                'Laporan Request Barang',
                ['Nomor', 'Status', 'Peminta', 'Tujuan', 'Jumlah Baris', 'Dibuat'],
                MaterialRequest::query()->withCount('items')->with('requester:id,name')->latest()->lazy()
                    ->map(fn ($r) => [
                        $r->number, $r->status, $r->requester?->name, $r->purpose,
                        $r->items_count, $r->created_at?->toDateTimeString(),
                    ]),
            ],
            'npbg' => [
                'Laporan NPBG',
                ['Nomor', 'Status', 'Klasifikasi', 'Gudang', 'Diambil Oleh', 'Tanggal'],
                Npbg::query()->with('warehouse:id,code')->latest()->lazy()->map(fn ($n) => [
                    $n->number, $n->status, $n->classification, $n->warehouse?->code,
                    $n->picked_up_by, $n->date?->toDateString(),
                ]),
            ],
            'ppb' => [
                'Laporan PPB',
                ['Nomor', 'Status', 'Sumber Request', 'Jumlah Baris', 'Tanggal'],
                Ppb::query()->withCount('items')->latest()->lazy()->map(fn ($p) => [
                    $p->number, $p->status, $p->source_request_id, $p->items_count, $p->date?->toDateString(),
                ]),
            ],
            'stock-opnames' => [
                'Laporan Stock Opname',
                ['Nomor', 'Status', 'Gudang', 'Jumlah Baris', 'Tanggal'],
                StockOpname::query()->withCount('items')->with('warehouse:id,code')->latest()->lazy()
                    ->map(fn ($o) => [
                        $o->number, $o->status, $o->warehouse?->code, $o->items_count, $o->scheduled_date?->toDateString(),
                    ]),
            ],
            'stock-movements' => [
                'Laporan Pergerakan Stok',
                ['Tanggal', 'Tipe', 'Arah', 'Kode Barang', 'Gudang', 'Qty', 'Aktual Sesudah', 'Catatan'],
                StockMovement::query()->with('item:id,code', 'warehouse:id,code')->latest('created_at')->lazy()
                    ->map(fn ($m) => [
                        $m->created_at?->toDateTimeString(), $m->movement_type,
                        $m->direction > 0 ? 'MASUK' : ($m->direction < 0 ? 'KELUAR' : '-'),
                        $m->item?->code, $m->warehouse?->code, $m->qty, $m->actual_after, $m->note,
                    ]),
            ],
        };
    }
}
