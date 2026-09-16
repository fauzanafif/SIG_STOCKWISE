<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MaterialRequest;
use App\Models\PurchaseProposal;
use App\Services\PurchaseProposalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseProposalController extends Controller
{
    public function __construct(private readonly PurchaseProposalService $service) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = PurchaseProposal::query()->with('site:id,code')->withCount('items')->latest();
        if (! $user->hasPermission('purchase_proposal.view')) {
            $query->where('requester_id', $user->id);
        }
        $request->whenFilled('status', fn ($v) => $query->where('status', strtoupper((string) $v)));
        if ($s = $request->string('search')->trim()->value()) {
            $query->where('number', 'like', "%{$s}%");
        }
        $page = $query->paginate(min((int) $request->integer('per_page', 20), 100))->withQueryString();

        return response()->json([
            'data' => collect($page->items())->map(fn (PurchaseProposal $p) => $this->row($p)),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(),
                'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(PurchaseProposal $purchaseProposal): JsonResponse
    {
        $purchaseProposal->load('items.item:id,code', 'items.unit:id,code', 'amendments', 'sourceRequest:id,number');

        return response()->json(['data' => $this->row($purchaseProposal) + [
            'source_request_number' => $purchaseProposal->sourceRequest?->number,
            'notes' => $purchaseProposal->notes,
            'items' => $purchaseProposal->items->map(fn ($l) => [
                'id' => $l->id, 'item_code' => $l->item?->code, 'description' => $l->description_raw,
                'qty' => $l->qty, 'unit' => $l->unit?->code, 'shortage_qty' => $l->shortage_qty,
                'deficit_snapshot' => $l->deficit_snapshot, 'priority_score_snapshot' => $l->priority_score_snapshot,
                'priority_level_snapshot' => $l->priority_level_snapshot,
                'qty_ordered' => $l->qty_ordered, 'qty_received' => $l->qty_received, 'line_status' => $l->line_status,
            ]),
            'amendments' => $purchaseProposal->amendments->map(fn ($a) => [
                'date' => $a->date?->toDateString(), 'type' => $a->type,
                'qty_before' => $a->qty_before, 'qty_after' => $a->qty_after, 'reason' => $a->reason,
            ]),
        ]]);
    }

    public function storeFromRequest(Request $request): JsonResponse
    {
        $data = $request->validate(['material_request_id' => ['required', 'integer', 'exists:material_requests,id']]);
        $ppb = $this->service->createFromRequest(MaterialRequest::findOrFail($data['material_request_id']), $request->user());

        return response()->json(['data' => $this->row($ppb->loadCount('items'))], 201);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['nullable', 'integer', 'exists:items,id'],
            'items.*.description_raw' => ['required_without:items.*.item_id', 'nullable', 'string', 'max:400'],
            'items.*.qty' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_id' => ['nullable', 'integer', 'exists:units,id'],
        ]);
        $ppb = $this->service->createManual($request->user(), $data);

        return response()->json(['data' => $this->row($ppb->loadCount('items'))], 201);
    }

    public function update(Request $request, PurchaseProposal $purchaseProposal): JsonResponse
    {
        $data = $request->validate([
            'notes' => ['sometimes', 'nullable', 'string'],
            'items' => ['sometimes', 'array', 'min:1'],
            'items.*.item_id' => ['nullable', 'integer', 'exists:items,id'],
            'items.*.description_raw' => ['required_without:items.*.item_id', 'nullable', 'string', 'max:400'],
            'items.*.qty' => ['required_with:items', 'numeric', 'gt:0'],
            'items.*.unit_id' => ['nullable', 'integer', 'exists:units,id'],
        ]);

        return $this->show($this->service->update($purchaseProposal, $data));
    }

    public function destroy(PurchaseProposal $purchaseProposal): JsonResponse
    {
        $this->service->delete($purchaseProposal);

        return response()->json(['message' => 'PPB dihapus.']);
    }

    public function submit(PurchaseProposal $purchaseProposal): JsonResponse
    {
        return $this->show($this->service->submit($purchaseProposal));
    }

    public function review(PurchaseProposal $purchaseProposal): JsonResponse
    {
        return $this->show($this->service->review($purchaseProposal));
    }

    public function approve(Request $request, PurchaseProposal $purchaseProposal): JsonResponse
    {
        return $this->show($this->service->approve($purchaseProposal, $request->user()));
    }

    public function reject(Request $request, PurchaseProposal $purchaseProposal): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->show($this->service->reject($purchaseProposal, $data['reason']));
    }

    public function amend(Request $request, PurchaseProposal $purchaseProposal): JsonResponse
    {
        $data = $request->validate([
            'ppb_item_id' => ['nullable', 'integer'],
            'type' => ['required', 'in:AMEND,CLOSE'],
            'qty_after' => ['nullable', 'numeric', 'min:0'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        return $this->show($this->service->amend(
            $purchaseProposal, $request->user(), $data['ppb_item_id'] ?? null, $data['type'], $data['qty_after'] ?? null, $data['reason']
        ));
    }

    private function row(PurchaseProposal $p): array
    {
        return [
            'id' => $p->id, 'number' => $p->number, 'status' => $p->status,
            'date' => $p->date?->toDateString(), 'items_count' => $p->items_count,
            'source_request_id' => $p->source_request_id,
            'approved_at' => $p->approved_at, 'created_at' => $p->created_at,
        ];
    }
}
