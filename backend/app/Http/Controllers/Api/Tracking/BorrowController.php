<?php

namespace App\Http\Controllers\Api\Tracking;

use App\Http\Controllers\Controller;
use App\Models\BorrowTransaction;
use App\Services\Tracking\BorrowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BorrowController extends Controller
{
    public function __construct(private readonly BorrowService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = BorrowTransaction::query()->with('item:id,code', 'unit:id,code', 'lenderVendor:id,name')->latest();
        $request->whenFilled('status', fn ($v) => $query->where('status', strtoupper((string) $v)));
        if ($s = $request->string('search')->trim()->value()) {
            $query->where(fn ($q) => $q->where('number', 'like', "%{$s}%")->orWhere('lender_name', 'like', "%{$s}%"));
        }
        $page = $query->paginate(min((int) $request->integer('per_page', 20), 100))->withQueryString();

        return response()->json([
            'data' => collect($page->items())->map(fn (BorrowTransaction $b) => $this->row($b)),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(BorrowTransaction $borrow): JsonResponse
    {
        return response()->json(['data' => $this->row($borrow->load('item:id,code', 'unit:id,code', 'lenderVendor:id,name', 'returnNpbg:id,number'))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'item_id' => ['nullable', 'integer', 'exists:items,id'],
            'description_raw' => ['required_without:item_id', 'nullable', 'string', 'max:400'],
            'qty' => ['required', 'numeric', 'gt:0'],
            'unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'lender_vendor_id' => ['nullable', 'integer', 'exists:vendors,id'],
            'lender_name' => ['nullable', 'string', 'max:200'],
            'receipt_ref' => ['nullable', 'string', 'max:60'],
            'borrowed_at' => ['nullable', 'date'],
            'condition_note' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json(['data' => $this->row($this->service->create($request->user(), $data))], 201);
    }

    public function update(Request $request, BorrowTransaction $borrow): JsonResponse
    {
        $data = $request->validate([
            'item_id' => ['nullable', 'integer', 'exists:items,id'],
            'description_raw' => ['nullable', 'string', 'max:400'],
            'qty' => ['sometimes', 'numeric', 'gt:0'],
            'unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'lender_vendor_id' => ['nullable', 'integer', 'exists:vendors,id'],
            'lender_name' => ['nullable', 'string', 'max:200'],
            'receipt_ref' => ['nullable', 'string', 'max:60'],
            'borrowed_at' => ['sometimes', 'date'],
            'condition_note' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json(['data' => $this->row($this->service->update($borrow, $data))]);
    }

    public function destroy(BorrowTransaction $borrow): JsonResponse
    {
        $this->service->delete($borrow);

        return response()->json(['message' => 'Borrow dihapus.']);
    }

    public function return(Request $request, BorrowTransaction $borrow): JsonResponse
    {
        $data = $request->validate([
            'qty' => ['required', 'numeric', 'gt:0'],
            'return_npbg_id' => ['nullable', 'integer', 'exists:goods_issues,id'],
            'returned_at' => ['nullable', 'date'],
            'condition_note' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json(['data' => $this->row($this->service->recordReturn($borrow, $data))]);
    }

    private function row(BorrowTransaction $b): array
    {
        return [
            'id' => $b->id, 'number' => $b->number, 'status' => $b->status,
            'item_code' => $b->relationLoaded('item') ? $b->item?->code : null,
            'description' => $b->description_raw, 'qty' => $b->qty, 'qty_returned' => $b->qty_returned,
            'unit' => $b->relationLoaded('unit') ? $b->unit?->code : null,
            'lender_name' => $b->relationLoaded('lenderVendor') ? ($b->lenderVendor?->name ?? $b->lender_name) : $b->lender_name,
            'receipt_ref' => $b->receipt_ref,
            'borrowed_at' => $b->borrowed_at?->toDateString(), 'returned_at' => $b->returned_at?->toDateString(),
            'return_npbg' => $b->relationLoaded('returnNpbg') ? $b->returnNpbg?->number : null,
            'condition_note' => $b->condition_note, 'created_at' => $b->created_at,
        ];
    }
}
