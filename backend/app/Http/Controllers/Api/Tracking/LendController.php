<?php

namespace App\Http\Controllers\Api\Tracking;

use App\Http\Controllers\Controller;
use App\Models\LendTransaction;
use App\Services\Tracking\LendService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LendController extends Controller
{
    public function __construct(private readonly LendService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = LendTransaction::query()->with('item:id,code', 'unit:id,code')->latest();
        $request->whenFilled('status', fn ($v) => $query->where('status', strtoupper((string) $v)));
        if ($s = $request->string('search')->trim()->value()) {
            $query->where(fn ($q) => $q->where('number', 'like', "%{$s}%")->orWhere('borrower_name', 'like', "%{$s}%"));
        }
        $page = $query->paginate(min((int) $request->integer('per_page', 20), 100))->withQueryString();

        return response()->json([
            'data' => collect($page->items())->map(fn (LendTransaction $l) => $this->row($l)),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(LendTransaction $lend): JsonResponse
    {
        return response()->json(['data' => $this->row($lend->load('item:id,code', 'unit:id,code', 'outNpbg:id,number', 'returnRi:id,number'))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'item_id' => ['nullable', 'integer', 'exists:items,id'],
            'description_raw' => ['required_without:item_id', 'nullable', 'string', 'max:400'],
            'qty' => ['required', 'numeric', 'gt:0'],
            'unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'purpose' => ['nullable', 'in:INTERNAL,PROJECT,RELASI'],
            'borrower_name' => ['nullable', 'string', 'max:200'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'est_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'out_npbg_id' => ['nullable', 'integer', 'exists:goods_issues,id'],
            'out_date' => ['nullable', 'date'],
            'condition_out' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json(['data' => $this->row($this->service->create($request->user(), $data))], 201);
    }

    public function update(Request $request, LendTransaction $lend): JsonResponse
    {
        $data = $request->validate([
            'item_id' => ['nullable', 'integer', 'exists:items,id'],
            'description_raw' => ['nullable', 'string', 'max:400'],
            'qty' => ['sometimes', 'numeric', 'gt:0'],
            'unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'purpose' => ['sometimes', 'in:INTERNAL,PROJECT,RELASI'],
            'borrower_name' => ['nullable', 'string', 'max:200'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'est_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'out_date' => ['sometimes', 'date'],
            'condition_out' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json(['data' => $this->row($this->service->update($lend, $data))]);
    }

    public function destroy(LendTransaction $lend): JsonResponse
    {
        $this->service->delete($lend);

        return response()->json(['message' => 'Lend dihapus.']);
    }

    public function return(Request $request, LendTransaction $lend): JsonResponse
    {
        $data = $request->validate([
            'qty' => ['required', 'numeric', 'gt:0'],
            'return_ri_id' => ['nullable', 'integer', 'exists:receivings,id'],
            'return_date' => ['nullable', 'date'],
            'condition_in' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json(['data' => $this->row($this->service->recordReturn($lend, $data))]);
    }

    private function row(LendTransaction $l): array
    {
        return [
            'id' => $l->id, 'number' => $l->number, 'status' => $l->status,
            'item_code' => $l->relationLoaded('item') ? $l->item?->code : null,
            'description' => $l->description_raw, 'qty' => $l->qty, 'qty_returned' => $l->qty_returned,
            'unit' => $l->relationLoaded('unit') ? $l->unit?->code : null,
            'purpose' => $l->purpose, 'borrower_name' => $l->borrower_name,
            'out_date' => $l->out_date?->toDateString(), 'due_date' => $l->due_date?->toDateString(),
            'return_date' => $l->return_date?->toDateString(),
            'out_npbg' => $l->relationLoaded('outNpbg') ? $l->outNpbg?->number : null,
            'return_ri' => $l->relationLoaded('returnRi') ? $l->returnRi?->number : null,
            'condition_out' => $l->condition_out, 'condition_in' => $l->condition_in,
            'created_at' => $l->created_at,
        ];
    }
}
