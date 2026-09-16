<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Services\PurchaseOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseOrderController extends Controller
{
    public function __construct(private readonly PurchaseOrderService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = PurchaseOrder::query()->with('vendor:id,name', 'ppb:id,number')->withCount('items')->latest();
        $request->whenFilled('status', fn ($v) => $query->where('status', strtoupper((string) $v)));
        if ($s = $request->string('search')->trim()->value()) {
            $query->where('number', 'like', "%{$s}%");
        }
        $page = $query->paginate(min((int) $request->integer('per_page', 20), 100))->withQueryString();

        return response()->json([
            'data' => collect($page->items())->map(fn (PurchaseOrder $p) => $this->row($p)),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(),
                'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $purchaseOrder->load('vendor', 'ppb:id,number', 'items.item:id,code', 'items.unit:id,code', 'receivings:id,number,status,purchase_order_id');

        return response()->json(['data' => $this->row($purchaseOrder) + [
            'vendor' => $purchaseOrder->vendor?->only('id', 'name'),
            'ppb_number' => $purchaseOrder->ppb?->number,
            'expected_date' => $purchaseOrder->expected_date?->toDateString(),
            'notes' => $purchaseOrder->notes,
            'items' => $purchaseOrder->items->map(fn ($l) => [
                'id' => $l->id, 'item_code' => $l->item?->code, 'description' => $l->description_raw,
                'qty' => $l->qty, 'unit' => $l->unit?->code, 'unit_price' => $l->unit_price,
                'line_total' => $l->line_total, 'qty_received' => $l->qty_received, 'line_status' => $l->line_status,
            ]),
            'receivings' => $purchaseOrder->receivings->map(fn ($r) => $r->only('id', 'number', 'status')),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'vendor_id' => ['required', 'integer', 'exists:vendors,id'],
            'ppb_id' => ['nullable', 'integer', 'exists:purchase_proposals,id'],
            'expected_date' => ['nullable', 'date'],
            'tax_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.ppb_item_id' => ['nullable', 'integer'],
            'lines.*.item_id' => ['nullable', 'integer', 'exists:items,id'],
            'lines.*.description_raw' => ['required_without:lines.*.item_id', 'nullable', 'string', 'max:400'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
        ]);
        $po = $this->service->create($request->user(), $data);

        return response()->json(['data' => $this->row($po->loadCount('items'))], 201);
    }

    public function approve(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        return $this->show($this->service->approve($purchaseOrder, $request->user()));
    }

    public function send(PurchaseOrder $purchaseOrder): JsonResponse
    {
        return $this->show($this->service->send($purchaseOrder));
    }

    public function cancel(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->show($this->service->cancel($purchaseOrder, $data['reason']));
    }

    private function row(PurchaseOrder $p): array
    {
        return [
            'id' => $p->id, 'number' => $p->number, 'status' => $p->status,
            'date' => $p->date?->toDateString(),
            'vendor_name' => $p->relationLoaded('vendor') ? $p->vendor?->name : null,
            'ppb_id' => $p->ppb_id, 'items_count' => $p->items_count,
            'total' => $p->total, 'created_at' => $p->created_at, 'approved_at' => $p->approved_at,
        ];
    }
}
