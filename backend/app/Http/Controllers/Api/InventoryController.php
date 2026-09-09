<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\InventoryAnalysisRun;
use App\Models\InventorySnapshot;
use App\Models\Item;
use App\Services\Inventory\InventoryAnalyzer;
use App\Services\Inventory\StockwiseEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->integer('per_page', 25), 100);

        $query = Inventory::query()
            ->with(['item:id,code,description,unit_id', 'item.unit:id,code', 'warehouse:id,code,name'])
            ->join('items', 'items.id', '=', 'inventory.item_id');

        $request->whenFilled('warehouse_id', fn ($v) => $query->where('inventory.warehouse_id', $v));
        $request->whenFilled('item_id', fn ($v) => $query->where('inventory.item_id', $v));
        if ($s = $request->string('search')->trim()->value()) {
            $query->where(fn ($q) => $q->where('items.code', 'like', "%{$s}%")->orWhere('items.description', 'like', "%{$s}%"));
        }
        $request->whenFilled('stock_known', fn ($v) => $query->where('inventory.stock_known', filter_var($v, FILTER_VALIDATE_BOOL)));

        $page = $query->select('inventory.*')->orderBy('items.code')->paginate($perPage)->withQueryString();

        return response()->json([
            'data' => collect($page->items())->map(fn (Inventory $i) => [
                'id' => $i->id,
                'item' => ['id' => $i->item->id, 'code' => $i->item->code, 'description' => $i->item->description,
                    'unit' => $i->item->unit?->code],
                'warehouse' => ['id' => $i->warehouse->id, 'code' => $i->warehouse->code],
                'actual_qty' => $i->actual_qty,
                'reserved_qty' => $i->reserved_qty,
                'available_qty' => $i->available_qty,
                'stock_known' => $i->stock_known,
                'last_counted_at' => $i->last_counted_at,
            ]),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(),
                'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(Item $item): JsonResponse
    {
        return response()->json([
            'data' => [
                'item' => $item->only('id', 'code', 'description'),
                'inventory' => $item->inventory()->with('warehouse:id,code,name')->get()->map(fn ($i) => [
                    'warehouse' => $i->warehouse->only('id', 'code', 'name'),
                    'actual_qty' => $i->actual_qty,
                    'reserved_qty' => $i->reserved_qty,
                    'available_qty' => $i->available_qty,
                    'stock_known' => $i->stock_known,
                ]),
                'movements' => $item->movements()->with('warehouse:id,code')
                    ->latest('created_at')->limit(50)->get()->map(fn ($m) => [
                        'type' => $m->movement_type,
                        'warehouse' => $m->warehouse->code,
                        'qty' => $m->qty,
                        'actual_before' => $m->actual_before,
                        'actual_after' => $m->actual_after,
                        'note' => $m->note,
                        'created_at' => $m->created_at,
                    ]),
            ],
        ]);
    }

    public function analysis(Request $request): JsonResponse
    {
        $perPage = min((int) $request->integer('per_page', 50), 200);

        $query = InventorySnapshot::query()
            ->whereNull('warehouse_id')
            ->with(['item:id,code,description,unit_id,lead_time_days', 'item.unit:id,code'])
            ->join('items', 'items.id', '=', 'inventory_snapshots.item_id');

        $request->whenFilled('status', fn ($v) => $query->where('inventory_snapshots.status', strtoupper((string) $v)));
        $request->whenFilled('priority_level', fn ($v) => $query->where('inventory_snapshots.priority_level', strtoupper((string) $v)));
        if ($s = $request->string('search')->trim()->value()) {
            $query->where(fn ($q) => $q->where('items.code', 'like', "%{$s}%")->orWhere('items.description', 'like', "%{$s}%"));
        }

        $sort = $request->string('sort', '-priority_score')->value();
        $dir = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $col = ltrim($sort, '-');
        $query->orderBy(in_array($col, ['priority_score', 'deficit', 'selisih', 'available'], true)
            ? "inventory_snapshots.$col" : 'items.code', $dir);

        $page = $query->select('inventory_snapshots.*')->paginate($perPage)->withQueryString();
        $run = InventoryAnalysisRun::latest('computed_at')->first();

        return response()->json([
            'data' => collect($page->items())->map(fn (InventorySnapshot $s) => [
                'item' => ['id' => $s->item->id, 'code' => $s->item->code,
                    'description' => $s->item->description, 'unit' => $s->item->unit?->code],
                'actual' => $s->actual, 'reserved' => $s->reserved, 'available' => $s->available,
                'stock_known' => $s->stock_known,
                'safety_stock' => $s->safety_stock, 'lead_time_days' => $s->lead_time_days,
                'selisih' => $s->selisih, 'status' => $s->status, 'deficit' => $s->deficit,
                'priority_score' => $s->priority_score, 'priority_level' => $s->priority_level,
                'recommendation' => $s->recommendation, 'recommended_qty' => $s->recommended_qty,
            ]),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(),
                'total' => $page->total(), 'last_page' => $page->lastPage()],
            'run' => $run ? [
                'lead_time_threshold' => $run->lead_time_threshold,
                'median_deficit' => $run->median_deficit,
                'item_count' => $run->item_count,
                'tidak_aman_count' => $run->tidak_aman_count,
                'computed_at' => $run->computed_at,
            ] : null,
        ]);
    }

    public function recompute(InventoryAnalyzer $analyzer, Request $request): JsonResponse
    {
        $run = $analyzer->run($request->user()?->id);

        return response()->json(['message' => 'Analisis diperbarui.', 'run' => $run]);
    }

    /** POST body: { lines: [{ item_id, warehouse_id, qty }] } -> projected stock + warning. */
    public function projected(Request $request, StockwiseEngine $engine): JsonResponse
    {
        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'integer', 'exists:items,id'],
            'lines.*.warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'lines.*.qty' => ['required', 'numeric', 'min:0'],
        ]);

        $lines = collect($data['lines'])->map(function ($line) {
            $item = Item::with('effectiveSafetyStock')->find($line['item_id']);

            $invQuery = $item->inventory();
            if (! empty($line['warehouse_id'])) {
                $invQuery->where('warehouse_id', $line['warehouse_id']);
            }
            $actual = (float) $invQuery->sum('actual_qty');
            $reserved = (float) $invQuery->sum('reserved_qty');
            $available = $actual - $reserved;
            $safety = (float) ($item->effectiveSafetyStock?->safety_stock ?? 0);
            $requested = (float) $line['qty'];
            $projected = $available - $requested;
            $below = $projected < $safety;

            return [
                'item_id' => $item->id,
                'code' => $item->code,
                'actual' => $actual, 'reserved' => $reserved, 'available' => $available,
                'safety_stock' => $safety,
                'lead_time_days' => $item->lead_time_days,
                'requested_qty' => $requested,
                'projected_stock' => $projected,
                'below_safety' => $below,
                'insufficient' => $projected < 0,
                'warning' => $below
                    ? 'Request ini akan menyebabkan stok berada di bawah Safety Stock.'
                    : null,
            ];
        });

        return response()->json(['data' => $lines]);
    }
}
