<?php

namespace App\Http\Controllers\Api\Tracking;

use App\Http\Controllers\Controller;
use App\Models\TyreChange;
use App\Services\Tracking\TyreChangeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TyreChangeController extends Controller
{
    public function __construct(private readonly TyreChangeService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = TyreChange::query()->with('asset:id,code,name')->latest();
        $request->whenFilled('status', fn ($v) => $query->where('status', strtoupper((string) $v)));
        $request->whenFilled('asset_id', fn ($v) => $query->where('asset_id', $v));
        $page = $query->paginate(min((int) $request->integer('per_page', 20), 100))->withQueryString();

        return response()->json([
            'data' => collect($page->items())->map(fn (TyreChange $t) => $this->row($t)),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(TyreChange $tyreChange): JsonResponse
    {
        return response()->json(['data' => $this->row($tyreChange->load('asset:id,code,name', 'outNpbg:id,number', 'inRi:id,number'))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'asset_id' => ['required', 'integer', 'exists:assets,id'],
            'change_date' => ['nullable', 'date'],
            'position' => ['nullable', 'string', 'max:20'],
            'out_npbg_id' => ['nullable', 'integer', 'exists:npbg,id'],
            'new_tyre_desc' => ['nullable', 'string', 'max:300'],
            'new_serial_raw' => ['nullable', 'string', 'max:80'],
            'old_tyre_desc' => ['nullable', 'string', 'max:300'],
            'old_serial_raw' => ['nullable', 'string', 'max:80'],
            'reason' => ['nullable', 'string', 'max:255'],
            'is_opening' => ['sometimes', 'boolean'],
        ]);

        return response()->json(['data' => $this->row($this->service->record($request->user(), $data))], 201);
    }

    public function update(Request $request, TyreChange $tyreChange): JsonResponse
    {
        $data = $request->validate([
            'position' => ['nullable', 'string', 'max:20'],
            'new_tyre_desc' => ['nullable', 'string', 'max:300'],
            'new_serial_raw' => ['nullable', 'string', 'max:80'],
            'old_tyre_desc' => ['nullable', 'string', 'max:300'],
            'old_serial_raw' => ['nullable', 'string', 'max:80'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json(['data' => $this->row($this->service->update($tyreChange, $data))]);
    }

    public function destroy(TyreChange $tyreChange): JsonResponse
    {
        $this->service->delete($tyreChange);

        return response()->json(['message' => 'Data ban dihapus.']);
    }

    public function close(Request $request, TyreChange $tyreChange): JsonResponse
    {
        $data = $request->validate([
            'in_ri_id' => ['nullable', 'integer', 'exists:receivings,id'],
            'in_date' => ['nullable', 'date'],
        ]);

        return response()->json(['data' => $this->row($this->service->close($tyreChange, $data))]);
    }

    private function row(TyreChange $t): array
    {
        return [
            'id' => $t->id, 'status' => $t->status,
            'asset_code' => $t->relationLoaded('asset') ? $t->asset?->code : null,
            'asset_name' => $t->relationLoaded('asset') ? $t->asset?->name : null,
            'change_date' => $t->change_date?->toDateString(), 'position' => $t->position, 'change_seq' => $t->change_seq,
            'new_tyre_desc' => $t->new_tyre_desc, 'new_serial_raw' => $t->new_serial_raw,
            'old_tyre_desc' => $t->old_tyre_desc, 'old_serial_raw' => $t->old_serial_raw,
            'is_opening' => $t->is_opening, 'reason' => $t->reason,
            'in_date' => $t->in_date?->toDateString(),
            'out_npbg' => $t->relationLoaded('outNpbg') ? $t->outNpbg?->number : null,
            'in_ri' => $t->relationLoaded('inRi') ? $t->inRi?->number : null,
            'created_at' => $t->created_at,
        ];
    }
}
