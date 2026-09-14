<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Item\StoreItemRequest;
use App\Http\Requests\Item\UpdateItemRequest;
use App\Http\Resources\ItemResource;
use App\Models\Item;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ItemController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min((int) $request->integer('per_page', 25), 100);

        $query = Item::filtered($request)->with([
            'category:id,name,path', 'unit:id,code,name', 'snapshot', 'effectiveSafetyStock',
            'defaultWarehouse:id,code,name', 'defaultLocation:id,code',
        ]);

        // sorting
        $sort = $request->string('sort', 'items.code')->value();
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');
        $sortable = [
            'code' => 'items.code', 'description' => 'items.description',
            'lead_time_days' => 'items.lead_time_days',
            'priority_score' => 'snap.priority_score', 'selisih' => 'snap.selisih',
            'deficit' => 'snap.deficit', 'available' => 'snap.available',
        ];
        $query->orderBy($sortable[$column] ?? 'items.code', $direction);

        return ItemResource::collection($query->paginate($perPage)->withQueryString());
    }

    /**
     * Distinct Kategori Anak 1/2/3 combinations actually present (from
     * Accurate sync) — one row per real branch, e.g.
     * {anak_1: "AUTOMOTIVE WHEELS & TIRES", anak_2: "BAN LUAR (TIRES)", anak_3: "BAN LUAR BENANG (NYLON TIRES)"}.
     * The frontend builds a 3-level cascading filter from this flat list
     * client-side (same approach as CategoryPicker.tsx for the Excel tree).
     * No "induk" combination: that level is always NULL, see index() above.
     */
    public function accurateCategoryOptions(): JsonResponse
    {
        $options = Item::query()
            ->whereNotNull('accurate_category_anak_1')
            ->select('accurate_category_anak_1', 'accurate_category_anak_2', 'accurate_category_anak_3')
            ->distinct()
            ->orderBy('accurate_category_anak_1')
            ->orderBy('accurate_category_anak_2')
            ->orderBy('accurate_category_anak_3')
            ->get();

        return response()->json(['data' => $options]);
    }

    /** Lightweight search for the item picker (requests / PPB). */
    public function lookup(Request $request): JsonResponse
    {
        $search = $request->string('search')->trim()->value();
        if (mb_strlen($search) < 2) {
            return response()->json(['data' => []]);
        }

        $items = Item::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->where('code', 'like', "%{$search}%")->orWhere('description', 'like', "%{$search}%"))
            ->with(['unit:id,code', 'snapshot:item_id,available,stock_known'])
            ->orderBy('code')
            ->limit(15)
            ->get()
            ->map(fn (Item $i) => [
                'id' => $i->id,
                'code' => $i->code,
                'description' => $i->description,
                'unit' => $i->unit?->code,
                'unit_id' => $i->unit_id,
                'default_warehouse_id' => $i->default_warehouse_id,
                'available' => $i->snapshot?->available,
                'stock_known' => (bool) ($i->snapshot?->stock_known ?? false),
            ]);

        return response()->json(['data' => $items]);
    }

    public function show(Item $item): ItemResource
    {
        return new ItemResource($item->load([
            'category', 'unit', 'snapshot', 'effectiveSafetyStock',
            'safetyStocks', 'aliases', 'inventory', 'defaultWarehouse', 'defaultLocation',
        ]));
    }

    public function store(StoreItemRequest $request): ItemResource
    {
        // refresh() (bukan fresh()) supaya default kolom DB ikut terbawa TANPA kehilangan
        // wasRecentlyCreated — itu yang membuat response status otomatis 201.
        $item = Item::create($request->validated());
        $item->refresh();

        return new ItemResource($item->load('category', 'unit', 'defaultWarehouse', 'defaultLocation'));
    }

    public function update(UpdateItemRequest $request, Item $item): ItemResource
    {
        $item->update($request->validated());

        return new ItemResource($item->load('category', 'unit', 'snapshot', 'defaultWarehouse', 'defaultLocation'));
    }

    public function destroy(Item $item): JsonResponse
    {
        $item->delete();

        return response()->json(['message' => 'Item dihapus.']);
    }
}
