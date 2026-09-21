<?php

namespace App\Http\Controllers\Api\Tracking;

use App\Http\Controllers\Controller;
use App\Models\StppTransaction;
use App\Services\Tracking\StppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StppController extends Controller
{
    public function __construct(private readonly StppService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = StppTransaction::query()->with('item:id,code', 'unit:id,code')->latest();
        $request->whenFilled('status', fn ($v) => $query->where('status', strtoupper((string) $v)));
        if ($s = $request->string('search')->trim()->value()) {
            $query->where(fn ($q) => $q->where('number', 'like', "%{$s}%")->orWhere('serial_no_raw', 'like', "%{$s}%"));
        }
        $page = $query->paginate(min((int) $request->integer('per_page', 20), 100))->withQueryString();

        return response()->json([
            'data' => collect($page->items())->map(fn (StppTransaction $s) => $this->row($s)),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(StppTransaction $stpp): JsonResponse
    {
        return response()->json(['data' => $this->row($stpp->load('item:id,code', 'unit:id,code', 'outNpbg:id,no_npbg', 'returnRi:id,number'))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'serial_no' => ['nullable', 'string', 'max:80'],
            'item_id' => ['nullable', 'integer', 'exists:items,id'],
            'description_raw' => ['required_without:item_id', 'nullable', 'string', 'max:400'],
            'qty' => ['nullable', 'numeric', 'gt:0'],
            'unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'holder_id' => ['nullable', 'integer', 'exists:employees,id'],
            'holder_name_raw' => ['nullable', 'string', 'max:150'],
            'placement_department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'placement_raw' => ['nullable', 'string', 'max:80'],
            'out_npbg_id' => ['nullable', 'integer', 'exists:npbg,id'],
            'out_date' => ['nullable', 'date'],
            'out_note' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json(['data' => $this->row($this->service->issue($request->user(), $data))], 201);
    }

    public function update(Request $request, StppTransaction $stpp): JsonResponse
    {
        $data = $request->validate([
            'item_id' => ['nullable', 'integer', 'exists:items,id'],
            'description_raw' => ['nullable', 'string', 'max:400'],
            'qty' => ['sometimes', 'numeric', 'gt:0'],
            'unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'holder_id' => ['nullable', 'integer', 'exists:employees,id'],
            'holder_name_raw' => ['nullable', 'string', 'max:150'],
            'placement_department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'placement_raw' => ['nullable', 'string', 'max:80'],
            'out_note' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json(['data' => $this->row($this->service->update($stpp, $data))]);
    }

    public function destroy(StppTransaction $stpp): JsonResponse
    {
        $this->service->delete($stpp);

        return response()->json(['message' => 'STPP dihapus.']);
    }

    public function withdraw(Request $request, StppTransaction $stpp): JsonResponse
    {
        $data = $request->validate([
            'return_ri_id' => ['nullable', 'integer', 'exists:receivings,id'],
            'return_date' => ['nullable', 'date'],
            'return_note' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json(['data' => $this->row($this->service->withdraw($stpp, $data))]);
    }

    public function reissue(Request $request, StppTransaction $stpp): JsonResponse
    {
        $data = $request->validate([
            'holder_id' => ['nullable', 'integer', 'exists:employees,id'],
            'holder_name_raw' => ['nullable', 'string', 'max:150'],
            'placement_department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'placement_raw' => ['nullable', 'string', 'max:80'],
            'out_npbg_id' => ['nullable', 'integer', 'exists:npbg,id'],
            'out_date' => ['nullable', 'date'],
        ]);

        return response()->json(['data' => $this->row($this->service->reissue($stpp, $request->user(), $data))], 201);
    }

    private function row(StppTransaction $s): array
    {
        return [
            'id' => $s->id, 'number' => $s->number, 'status' => $s->status,
            'serial_no' => $s->serial_no_raw,
            'item_code' => $s->relationLoaded('item') ? $s->item?->code : null,
            'description' => $s->description_raw, 'qty' => $s->qty,
            'unit' => $s->relationLoaded('unit') ? $s->unit?->code : null,
            'holder' => $s->holder_name_raw, 'placement' => $s->placement_raw,
            'out_date' => $s->out_date?->toDateString(), 'return_date' => $s->return_date?->toDateString(),
            'out_npbg' => $s->relationLoaded('outNpbg') ? $s->outNpbg?->no_npbg : null,
            'return_ri' => $s->relationLoaded('returnRi') ? $s->returnRi?->number : null,
            'out_note' => $s->out_note, 'return_note' => $s->return_note, 'created_at' => $s->created_at,
        ];
    }
}
