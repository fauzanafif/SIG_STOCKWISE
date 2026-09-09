<?php

namespace App\Support\Import\Importers;

use App\Models\Category;
use App\Models\Inventory;
use App\Models\Item;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\Inventory\StockLedgerService;
use App\Support\Import\ImportResult;
use App\Support\Import\Importer;
use App\Support\Import\SpreadsheetReader;
use App\Support\Import\Value;
use Illuminate\Support\Str;

/**
 * Item master from DATA.xlsx / DATABASE UTAMA (~5.900 baris).
 *
 * - Kode Barang = business key.
 * - `SISA STOK (dd/mm/yyyy)` teks "STOK N PCS" -> opening balance movement bila angka
 *   ada & gudang diketahui; kalau kosong -> inventory `stock_known = false` (UNKNOWN, D2/NC-4).
 * - SAFETY STOCK / MIN PR di sheet ini kosong -> lihat ItemSafetyStockImporter.
 */
class ItemImporter implements Importer
{
    public function __construct(private readonly StockLedgerService $ledger) {}

    public function key(): string
    {
        return 'items';
    }

    public function description(): string
    {
        return 'Master barang + stok awal (DATA.xlsx DATABASE UTAMA)';
    }

    public function dependsOn(): array
    {
        return ['categories', 'units', 'warehouses'];
    }

    public function import(SpreadsheetReader $reader): ImportResult
    {
        $result = new ImportResult($this->key());
        $path = config('stockwise.import_path').DIRECTORY_SEPARATOR.config('stockwise.files.master');

        if (! is_file($path)) {
            $result->note('DATA.xlsx tidak ditemukan.');

            return $result;
        }

        $categories = Category::pluck('id', 'path');
        $units = Unit::pluck('id', 'code');
        $warehouses = Warehouse::pluck('id', 'code');
        $locations = WarehouseLocation::query()->get()->keyBy(fn ($l) => $l->warehouse_id.'|'.$l->code);

        $rows = $reader->rows($path, 'DATABASE UTAMA', 5);
        $result->read = $rows->count();
        $openingCount = 0;

        foreach ($rows as $row) {
            $code = Value::str($row['Kode Barang'] ?? null);
            $description = Value::str($row['Deskripsi Barang'] ?? null);

            if ($code === null || $description === null) {
                $result->skipped++;

                continue;
            }

            // category path
            $segments = array_values(array_filter([
                Value::str($row['Kategori Induk'] ?? null),
                Value::str($row['Kategori Anak 1'] ?? null),
                Value::str($row['Kategori Anak 2'] ?? null),
                Value::str($row['Kategori Anak 3'] ?? null),
            ]));
            $categoryId = $categories[implode(' > ', array_map('trim', $segments))] ?? null;

            $unitCode = Value::str($row['UoM'] ?? null);
            $unitId = $unitCode ? ($units[Str::upper(trim($unitCode))] ?? null) : null;

            $whCode = ($w = Value::str($row['LETAK GUDANG'] ?? null))
                ? Str::of($w)->squish()->upper()->value() : null;
            $warehouseId = $whCode ? ($warehouses[$whCode] ?? null) : null;

            $rakCode = ($r = Value::str($row['LETAK RAK'] ?? null))
                ? Str::of($r)->squish()->upper()->value() : null;
            $locationId = ($warehouseId && $rakCode)
                ? ($locations[$warehouseId.'|'.$rakCode]->id ?? null) : null;

            $leadTime = Value::int($row['LEAD TIME'] ?? null);

            $item = Item::withTrashed()->firstOrNew(['code' => $code]);
            $wasNew = ! $item->exists;

            $item->fill([
                'description' => $description,
                'category_id' => $categoryId,
                'unit_id' => $unitId,
                'needs_blueprint' => Value::boolYaTidak($row['Perlu Blueprint?'] ?? null),
                'lead_time_days' => $leadTime !== null && $leadTime >= 0 && $leadTime <= 3650 ? $leadTime : null,
                'default_warehouse_id' => $warehouseId,
                'default_location_id' => $locationId,
                'blueprint_3d_ref' => Value::str($row['BLUEPRINT 3D VIEW'] ?? null),
                'source' => 'excel:DATA.xlsx',
                'is_active' => true,
            ]);
            if ($item->trashed()) {
                $item->restore();
            }
            $item->save();
            $wasNew ? $result->created++ : $result->updated++;

            // Opening stock
            $sisa = Value::sisaStok($row['SISA STOK (22/08/2026)'] ?? $row['SISA STOK'] ?? null);
            if ($warehouseId) {
                $inv = Inventory::firstOrCreate(
                    ['item_id' => $item->id, 'warehouse_id' => $warehouseId],
                    ['actual_qty' => 0, 'reserved_qty' => 0, 'stock_known' => false]
                );

                if ($sisa !== null && ! $inv->stock_known && (float) $inv->actual_qty === 0.0) {
                    if ($sisa > 0) {
                        $this->ledger->openingBalance($item->id, $warehouseId, $sisa);
                    } else {
                        $inv->update(['stock_known' => true]); // explicit zero
                    }
                    $openingCount++;
                }
            }
        }

        $result->note("Opening balance dibuat untuk {$openingCount} item; sisanya stok = UNKNOWN.");

        return $result;
    }
}
