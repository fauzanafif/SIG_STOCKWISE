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

        $query = Item::query()
            ->with(['category:id,name,path', 'unit:id,code,name', 'snapshot', 'effectiveSafetyStock'])
            ->leftJoin('categories', 'categories.id', '=', 'items.category_id')
            ->leftJoin('inventory_snapshots as snap', function ($join) {
                $join->on('snap.item_id', '=', 'items.id')->whereNull('snap.warehouse_id');
            })
            ->select('items.*');

        // text
        if ($search = $request->string('search')->trim()->value()) {
            $query->where(function ($q) use ($search) {
                $q->where('items.code', 'like', "%{$search}%")
                    ->orWhere('items.description', 'like', "%{$search}%");
            });
        }
        $request->whenFilled('code', fn ($v) => $query->where('items.code', 'like', "%{$v}%"));
        $request->whenFilled('description', fn ($v) => $query->where('items.description', 'like', "%{$v}%"));

        // category (by name at any level, via path)
        $request->whenFilled('category_induk', fn ($v) => $query->where('categories.path', 'like', "{$v}%"));
        foreach (['category_anak_1', 'category_anak_2', 'category_anak_3', 'category'] as $key) {
            $request->whenFilled($key, fn ($v) => $query->where('categories.path', 'like', "%{$v}%"));
        }
        $request->whenFilled('category_id', fn ($v) => $query->where('items.category_id', $v));

        // simple attrs
        $request->whenFilled('unit_id', fn ($v) => $query->where('items.unit_id', $v));
        $request->whenFilled('warehouse_id', fn ($v) => $query->where('items.default_warehouse_id', $v));
        $request->whenFilled('needs_blueprint', fn ($v) => $query->where('items.needs_blueprint', filter_var($v, FILTER_VALIDATE_BOOL)));
        $request->whenFilled('is_active', fn ($v) => $query->where('items.is_active', filter_var($v, FILTER_VALIDATE_BOOL)));

        // analysis-derived
        $request->whenFilled('status', fn ($v) => $query->where('snap.status', strtoupper((string) $v)));
        $request->whenFilled('priority_level', fn ($v) => $query->where('snap.priority_level', strtoupper((string) $v)));
        $request->whenFilled('lead_time_min', fn ($v) => $query->where('items.lead_time_days', '>=', (int) $v));
        $request->whenFilled('lead_time_max', fn ($v) => $query->where('items.lead_time_days', '<=', (int) $v));
        $request->whenFilled('selisih_min', fn ($v) => $query->where('snap.selisih', '>=', (float) $v));
        $request->whenFilled('selisih_max', fn ($v) => $query->where('snap.selisih', '<=', (float) $v));

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

    public function show(Item $item): ItemResource
    {
        return new ItemResource($item->load([
            'category', 'unit', 'snapshot', 'effectiveSafetyStock',
            'safetyStocks', 'aliases', 'inventory',
        ]));
    }

    public function store(StoreItemRequest $request): ItemResource
    {
        $item = Item::create($request->validated());

        return new ItemResource($item->load('category', 'unit'));
    }

    public function update(UpdateItemRequest $request, Item $item): ItemResource
    {
        $item->update($request->validated());

        return new ItemResource($item->load('category', 'unit', 'snapshot'));
    }

    public function destroy(Item $item): JsonResponse
    {
        $item->delete();

        return response()->json(['message' => 'Item dihapus.']);
    }
}
