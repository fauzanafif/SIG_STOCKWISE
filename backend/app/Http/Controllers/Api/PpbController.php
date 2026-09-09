<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MaterialRequest;
use App\Models\Ppb;
use App\Services\PpbService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PpbController extends Controller
{
    public function __construct(private readonly PpbService $service) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Ppb::query()->with('site:id,code')->withCount('items')->latest();
        if (! $user->hasPermission('ppb.view')) {
            $query->where('requester_id', $user->id);
        }
        $request->whenFilled('status', fn ($v) => $query->where('status', strtoupper((string) $v)));
        if ($s = $request->string('search')->trim()->value()) {
            $query->where('number', 'like', "%{$s}%");
        }
        $page = $query->paginate(min((int) $request->integer('per_page', 20), 100))->withQueryString();

        return response()->json([
            'data' => collect($page->items())->map(fn (Ppb $p) => $this->row($p)),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(),
                'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(Ppb $ppb): JsonResponse
    {
        $ppb->load('items.item:id,code', 'items.unit:id,code', 'amendments', 'sourceRequest:id,number');

        return response()->json(['data' => $this->row($ppb) + [
            'source_request_number' => $ppb->sourceRequest?->number,
            'notes' => $ppb->notes,
            'items' => $ppb->items->map(fn ($l) => [
                'id' => $l->id, 'item_code' => $l->item?->code, 'description' => $l->description_raw,
                'qty' => $l->qty, 'unit' => $l->unit?->code, 'shortage_qty' => $l->shortage_qty,
                'deficit_snapshot' => $l->deficit_snapshot, 'priority_score_snapshot' => $l->priority_score_snapshot,
                'priority_level_snapshot' => $l->priority_level_snapshot,
                'qty_ordered' => $l->qty_ordered, 'qty_received' => $l->qty_received, 'line_status' => $l->line_status,
            ]),
            'amendments' => $ppb->amendments->map(fn ($a) => [
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

    public function submit(Ppb $ppb): JsonResponse
    {
        return $this->show($this->service->submit($ppb));
    }

    public function review(Ppb $ppb): JsonResponse
    {
        return $this->show($this->service->review($ppb));
    }

    public function approve(Request $request, Ppb $ppb): JsonResponse
    {
        return $this->show($this->service->approve($ppb, $request->user()));
    }

    public function reject(Request $request, Ppb $ppb): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->show($this->service->reject($ppb, $data['reason']));
    }

    public function amend(Request $request, Ppb $ppb): JsonResponse
    {
        $data = $request->validate([
            'ppb_item_id' => ['nullable', 'integer'],
            'type' => ['required', 'in:AMEND,CLOSE'],
            'qty_after' => ['nullable', 'numeric', 'min:0'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        return $this->show($this->service->amend(
            $ppb, $request->user(), $data['ppb_item_id'] ?? null, $data['type'], $data['qty_after'] ?? null, $data['reason']
        ));
    }

    private function row(Ppb $p): array
    {
        return [
            'id' => $p->id, 'number' => $p->number, 'status' => $p->status,
            'date' => $p->date?->toDateString(), 'items_count' => $p->items_count,
            'source_request_id' => $p->source_request_id,
            'approved_at' => $p->approved_at, 'created_at' => $p->created_at,
        ];
    }
}
