<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Receiving;
use App\Services\ReceivingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReceivingController extends Controller
{
    public function __construct(private readonly ReceivingService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = Receiving::query()->with('vendor:id,name', 'purchaseOrder:id,number', 'warehouse:id,code')
            ->withCount('items')->latest();
        $request->whenFilled('status', fn ($v) => $query->where('status', strtoupper((string) $v)));
        $request->whenFilled('source_type', fn ($v) => $query->where('source_type', strtoupper((string) $v)));
        if ($s = $request->string('search')->trim()->value()) {
            $query->where('number', 'like', "%{$s}%");
        }
        $page = $query->paginate(min((int) $request->integer('per_page', 20), 100))->withQueryString();

        return response()->json([
            'data' => collect($page->items())->map(fn (Receiving $r) => $this->row($r)),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(),
                'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(Receiving $receiving): JsonResponse
    {
        $receiving->load('vendor', 'purchaseOrder:id,number', 'warehouse:id,code,name', 'items.item:id,code', 'items.unit:id,code');

        return response()->json(['data' => $this->row($receiving) + [
            'vendor' => $receiving->vendor?->only('id', 'name'),
            'po_number' => $receiving->purchaseOrder?->number,
            'surat_jalan_no' => $receiving->surat_jalan_no,
            'notes' => $receiving->notes,
            'items' => $receiving->items->map(fn ($l) => [
                'id' => $l->id, 'item_code' => $l->item?->code, 'description' => $l->description_raw,
                'qty_expected' => $l->qty_expected, 'qty_received' => $l->qty_received,
                'qty_accepted' => $l->qty_accepted, 'qty_rejected' => $l->qty_rejected,
                'unit' => $l->unit?->code, 'into_stock' => $l->into_stock, 'condition_note' => $l->condition_note,
            ]),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'purchase_order_id' => ['nullable', 'integer', 'exists:purchase_orders,id'],
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'source_type' => ['sometimes', 'string', 'max:24'],
            'vendor_id' => ['nullable', 'integer', 'exists:vendors,id'],
            'surat_jalan_no' => ['nullable', 'string', 'max:60'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.purchase_order_item_id' => ['nullable', 'integer'],
            'lines.*.item_id' => ['nullable', 'integer', 'exists:items,id'],
            'lines.*.description_raw' => ['required_without:lines.*.item_id', 'nullable', 'string', 'max:400'],
            'lines.*.qty_received' => ['required', 'numeric', 'gt:0'],
            'lines.*.qty_accepted' => ['nullable', 'numeric', 'min:0'],
            'lines.*.unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'lines.*.into_stock' => ['sometimes', 'boolean'],
            'lines.*.condition_note' => ['nullable', 'string', 'max:255'],
        ]);
        $ri = $this->service->create($request->user(), $data);

        return response()->json(['data' => $this->row($ri->loadCount('items'))], 201);
    }

    public function confirm(Request $request, Receiving $receiving): JsonResponse
    {
        return $this->show($this->service->confirm($receiving, $request->user()));
    }

    public function reject(Request $request, Receiving $receiving): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->show($this->service->reject($receiving, $data['reason']));
    }

    private function row(Receiving $r): array
    {
        return [
            'id' => $r->id, 'number' => $r->number, 'status' => $r->status, 'source_type' => $r->source_type,
            'date' => $r->date?->toDateString(),
            'vendor_name' => $r->relationLoaded('vendor') ? $r->vendor?->name : null,
            'po_number' => $r->relationLoaded('purchaseOrder') ? $r->purchaseOrder?->number : null,
            'warehouse' => $r->relationLoaded('warehouse') ? $r->warehouse?->code : null,
            'items_count' => $r->items_count, 'confirmed_at' => $r->confirmed_at, 'created_at' => $r->created_at,
        ];
    }
}
