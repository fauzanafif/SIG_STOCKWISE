<?php

namespace App\Http\Controllers\Api\Tracking;

use App\Http\Controllers\Controller;
use App\Models\MaintenanceOrder;
use App\Models\MaintenanceOrderSub;
use App\Services\Tracking\MaintenanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MaintenanceController extends Controller
{
    public function __construct(private readonly MaintenanceService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = MaintenanceOrder::query()->with('asset:id,code,name')->withCount('subs')->latest();
        $request->whenFilled('status', fn ($v) => $query->where('status', strtoupper((string) $v)));
        if ($s = $request->string('search')->trim()->value()) {
            $query->where('number', 'like', "%{$s}%");
        }
        $page = $query->paginate(min((int) $request->integer('per_page', 20), 100))->withQueryString();

        return response()->json([
            'data' => collect($page->items())->map(fn (MaintenanceOrder $o) => $this->row($o)),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(MaintenanceOrder $maintenanceOrder): JsonResponse
    {
        $maintenanceOrder->load(['asset:id,code,name', 'subs.workshop:id,name', 'subs' => fn ($q) => $q->orderBy('sub_no')]);

        return response()->json(['data' => $this->row($maintenanceOrder) + [
            'subs' => $maintenanceOrder->subs->map(fn ($s) => [
                'id' => $s->id, 'sub_no' => $s->sub_no, 'workshop' => $s->workshop?->name ?? $s->workshop_raw,
                'problem_detail' => $s->problem_detail, 'status' => $s->status,
                'finish_date' => $s->finish_date?->toDateString(), 'result_note' => $s->result_note,
            ]),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'asset_id' => ['required', 'integer', 'exists:assets,id'],
            'site_id' => ['required', 'integer', 'exists:sites,id'],
            'report_date' => ['nullable', 'date'],
            'reported_by' => ['nullable', 'integer', 'exists:employees,id'],
            'problem_summary' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json(['data' => $this->row($this->service->createOrder($request->user(), $data)->loadCount('subs'))], 201);
    }

    public function addSub(Request $request, MaintenanceOrder $maintenanceOrder): JsonResponse
    {
        $data = $request->validate([
            'workshop_id' => ['nullable', 'integer', 'exists:workshops,id'],
            'workshop_raw' => ['nullable', 'string', 'max:80'],
            'problem_detail' => ['nullable', 'string', 'max:2000'],
            'npbg_id' => ['nullable', 'integer', 'exists:npbg,id'],
        ]);
        $this->service->addSub($maintenanceOrder, $data);

        return $this->show($maintenanceOrder->fresh());
    }

    public function completeSub(Request $request, MaintenanceOrderSub $sub): JsonResponse
    {
        $data = $request->validate([
            'finish_date' => ['nullable', 'date'],
            'result_note' => ['required', 'string', 'max:2000'],
            'ri_id' => ['nullable', 'integer', 'exists:receivings,id'],
        ]);
        $this->service->completeSub($sub, $data);

        return $this->show($sub->order()->first());
    }

    private function row(MaintenanceOrder $o): array
    {
        return [
            'id' => $o->id, 'number' => $o->number, 'status' => $o->status,
            'asset_code' => $o->relationLoaded('asset') ? $o->asset?->code : null,
            'asset_name' => $o->relationLoaded('asset') ? $o->asset?->name : null,
            'report_date' => $o->report_date?->toDateString(), 'completed_at' => $o->completed_at?->toDateString(),
            'problem_summary' => $o->problem_summary, 'subs_count' => $o->subs_count, 'created_at' => $o->created_at,
        ];
    }
}
