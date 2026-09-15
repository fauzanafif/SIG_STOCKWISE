<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StockOpname;
use App\Models\StockOpnameItem;
use App\Services\StockOpnameService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockOpnameController extends Controller
{
    public function __construct(private readonly StockOpnameService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = StockOpname::query()
            ->with(['warehouse:id,code', 'counter:id,name'])
            ->withCount([
                'items',
                'items as counted_count' => fn ($q) => $q->where('count_status', 'COUNTED'),
                'items as diff_count' => fn ($q) => $q->whereRaw('abs(difference) > 0.001'),
            ])
            ->latest();
        $request->whenFilled('status', fn ($v) => $query->where('status', strtoupper((string) $v)));
        $request->whenFilled('warehouse_id', fn ($v) => $query->where('warehouse_id', $v));

        $page = $query->paginate(min((int) $request->integer('per_page', 20), 100))->withQueryString();

        return response()->json([
            'data' => collect($page->items())->map(fn (StockOpname $o) => $this->summary($o)),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(),
                'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(Request $request, StockOpname $stockOpname): JsonResponse
    {
        $stockOpname->load([
            'warehouse:id,code,name', 'counter:id,name', 'scheduledBy:id,name', 'reviewedBy:id,name',
            'items.item:id,code,description',
        ])
            ->loadCount([
                'items',
                'items as counted_count' => fn ($q) => $q->where('count_status', 'COUNTED'),
                'items as diff_count' => fn ($q) => $q->whereRaw('abs(difference) > 0.001'),
            ]);

        // Blind count: whoever only counts (opname.count, e.g. admin lapangan) never sees the
        // master-data system_qty — otherwise they'd just copy it instead of counting for real.
        // Only the reviewer (opname.review, admin gudang) sees system_qty and the reconciliation
        // (system_qty and physical_qty combined would reveal system_qty to the counter anyway).
        $canReview = $request->user()->hasPermission('opname.review');

        return response()->json([
            'data' => $this->summary($stockOpname) + [
                'items' => $stockOpname->items->map(fn ($l) => $this->lineRow($l, $canReview)),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function lineRow(StockOpnameItem $l, bool $canReview): array
    {
        $row = [
            'id' => $l->id,
            'item_id' => $l->item_id,
            'item_code' => $l->item?->code,
            'description' => $l->item?->description,
            'physical_qty' => $l->physical_qty,
            'note' => $l->note,
            'count_status' => $l->count_status,
            'review_status' => $l->review_status,
        ];

        if ($canReview) {
            $counted = $l->physical_qty !== null;
            $row['system_qty'] = $l->system_qty;
            $row['difference'] = $l->difference;
            $row['match_status'] = ! $counted ? null : (abs((float) $l->difference) < 0.001 ? 'VALID' : 'INVALID');
            $row['diff_label'] = ! $counted ? null : $this->diffLabel((float) $l->difference);
        }

        return $row;
    }

    private function diffLabel(float $diff): string
    {
        if (abs($diff) < 0.001) {
            return '0';
        }

        return ($diff > 0 ? '+' : '-').rtrim(rtrim(number_format(abs($diff), 2, '.', ''), '0'), '.');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'scheduled_date' => ['required', 'date'],
            'type' => ['sometimes', 'in:FULL,PARTIAL,OPENING'],
            'item_ids' => ['nullable', 'array'],
            'item_ids.*' => ['integer', 'exists:items,id'],
        ]);

        $opname = $this->service->schedule(
            $request->user(),
            $data['warehouse_id'],
            $data['scheduled_date'],
            $data['type'] ?? 'PARTIAL',
            $data['item_ids'] ?? null,
        );

        return response()->json(['data' => $this->summary($opname->loadCount('items'))], 201);
    }

    public function start(Request $request, StockOpname $stockOpname): JsonResponse
    {
        $this->service->start($stockOpname, $request->user());

        return $this->show($request, $stockOpname);
    }

    public function count(Request $request, StockOpname $stockOpname, int $item): JsonResponse
    {
        $data = $request->validate([
            'physical_qty' => ['required', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $line = $stockOpname->items()->findOrFail($item);
        $this->service->count($line, $data['physical_qty'], $data['note'] ?? null);

        return $this->show($request, $stockOpname);
    }

    public function submit(Request $request, StockOpname $stockOpname): JsonResponse
    {
        $this->service->submit($stockOpname);

        return $this->show($request, $stockOpname);
    }

    public function review(Request $request, StockOpname $stockOpname): JsonResponse
    {
        $data = $request->validate([
            'decisions' => ['required', 'array', 'min:1'],
            'decisions.*.id' => ['required', 'integer'],
            'decisions.*.decision' => ['required', 'in:APPROVED,REJECTED,RECOUNT'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $this->service->review($stockOpname, $request->user(), $data['decisions'], $data['note'] ?? null);

        return $this->show($request, $stockOpname);
    }

    private function summary(StockOpname $o): array
    {
        return [
            'id' => $o->id,
            'number' => $o->number,
            'status' => $o->status,
            'type' => $o->type,
            'warehouse' => $o->relationLoaded('warehouse') ? $o->warehouse?->only('id', 'code', 'name') : null,
            'scheduled_date' => $o->scheduled_date?->toDateString(),
            'scheduled_by' => $o->relationLoaded('scheduledBy') ? $o->scheduledBy?->name : null,
            'counter' => $o->relationLoaded('counter') ? $o->counter?->name : null,
            'reviewer' => $o->relationLoaded('reviewedBy') ? $o->reviewedBy?->name : null,
            'items_count' => $o->items_count,
            'counted_count' => $o->counted_count ?? null,
            'diff_count' => $o->diff_count ?? null,
            'created_at' => $o->created_at,
            'started_at' => $o->started_at,
            'submitted_at' => $o->submitted_at,
            'reviewed_at' => $o->reviewed_at,
            'review_note' => $o->review_note,
        ];
    }
}
