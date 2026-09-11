<?php

namespace App\Http\Controllers\Api\Tracking;

use App\Http\Controllers\Controller;
use App\Models\UsedReturn;
use App\Services\Tracking\UsedReturnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UsedReturnController extends Controller
{
    public function __construct(private readonly UsedReturnService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = UsedReturn::query()->withCount('items')->latest();
        $request->whenFilled('status', fn ($v) => $query->where('status', strtoupper((string) $v)));
        if ($s = $request->string('search')->trim()->value()) {
            $query->where('number', 'like', "%{$s}%")->orWhere('npbg_ref_raw', 'like', "%{$s}%");
        }
        $page = $query->paginate(min((int) $request->integer('per_page', 20), 100))->withQueryString();

        return response()->json([
            'data' => collect($page->items())->map(fn (UsedReturn $u) => $this->row($u)),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(UsedReturn $usedReturn): JsonResponse
    {
        $usedReturn->load('items.item:id,code', 'items.componentType:id,name', 'npbg:id,number', 'ri:id,number');

        return response()->json(['data' => $this->row($usedReturn) + [
            'npbg_number' => $usedReturn->npbg?->number,
            'ri_number' => $usedReturn->ri?->number,
            'note' => $usedReturn->note,
            'items' => $usedReturn->items->map(fn ($l) => [
                'id' => $l->id, 'item_code' => $l->item?->code,
                'component_type' => $l->componentType?->name,
                'description' => $l->description_raw, 'qty' => $l->qty,
                'condition' => $l->condition, 'into_stock' => $l->into_stock,
            ]),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'npbg_id' => ['nullable', 'integer', 'exists:npbg,id'],
            'npbg_ref_raw' => ['nullable', 'string', 'max:60'],
            'return_date' => ['nullable', 'date'],
            'format' => ['nullable', 'in:COMPONENT_MATRIX,ITEM_LINE'],
            'note' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['nullable', 'integer', 'exists:items,id'],
            'items.*.component_type_id' => ['nullable', 'integer', 'exists:used_return_component_types,id'],
            'items.*.description_raw' => ['nullable', 'string', 'max:400'],
            'items.*.qty' => ['required', 'numeric'],
            'items.*.unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'items.*.condition' => ['nullable', 'in:REUSABLE,SCRAP,DAMAGED,USED,reusable,scrap,damaged,used'],
            'items.*.into_stock' => ['sometimes', 'boolean'],
        ]);

        return response()->json(['data' => $this->row($this->service->create($request->user(), $data)->loadCount('items'))], 201);
    }

    public function update(Request $request, UsedReturn $usedReturn): JsonResponse
    {
        $data = $request->validate([
            'npbg_ref_raw' => ['nullable', 'string', 'max:60'],
            'return_date' => ['sometimes', 'date'],
            'note' => ['nullable', 'string', 'max:2000'],
            'items' => ['sometimes', 'array', 'min:1'],
            'items.*.item_id' => ['nullable', 'integer', 'exists:items,id'],
            'items.*.component_type_id' => ['nullable', 'integer', 'exists:used_return_component_types,id'],
            'items.*.description_raw' => ['nullable', 'string', 'max:400'],
            'items.*.qty' => ['required_with:items', 'numeric'],
            'items.*.unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'items.*.condition' => ['nullable', 'in:REUSABLE,SCRAP,DAMAGED,USED,reusable,scrap,damaged,used'],
            'items.*.into_stock' => ['sometimes', 'boolean'],
        ]);
        $this->service->update($usedReturn, $data);

        return $this->show($usedReturn->fresh());
    }

    public function destroy(UsedReturn $usedReturn): JsonResponse
    {
        $this->service->delete($usedReturn);

        return response()->json(['message' => 'Pengembalian bekas dihapus.']);
    }

    public function close(Request $request, UsedReturn $usedReturn): JsonResponse
    {
        $data = $request->validate([
            'ri_id' => ['nullable', 'integer', 'exists:receivings,id'],
            'return_date' => ['nullable', 'date'],
        ]);
        $this->service->close($usedReturn, $data);

        return $this->show($usedReturn->fresh());
    }

    private function row(UsedReturn $u): array
    {
        return [
            'id' => $u->id, 'number' => $u->number, 'status' => $u->status, 'format' => $u->format,
            'npbg_ref' => $u->npbg_ref_raw, 'return_date' => $u->return_date?->toDateString(),
            'items_count' => $u->items_count, 'created_at' => $u->created_at,
        ];
    }
}
