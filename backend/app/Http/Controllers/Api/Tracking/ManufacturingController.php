<?php

namespace App\Http\Controllers\Api\Tracking;

use App\Http\Controllers\Controller;
use App\Models\ManufacturingOrder;
use App\Models\ManufacturingOrderSub;
use App\Services\Tracking\ManufacturingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ManufacturingController extends Controller
{
    public function __construct(private readonly ManufacturingService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = ManufacturingOrder::query()->with('vendor:id,name')->withCount('subs')->latest();
        $request->whenFilled('status', fn ($v) => $query->where('status', strtoupper((string) $v)));
        $request->whenFilled('kind', fn ($v) => $query->where('kind', strtoupper((string) $v)));
        if ($s = $request->string('search')->trim()->value()) {
            $query->where('number', 'like', "%{$s}%")->orWhere('product_name', 'like', "%{$s}%");
        }
        $page = $query->paginate(min((int) $request->integer('per_page', 20), 100))->withQueryString();

        return response()->json([
            'data' => collect($page->items())->map(fn (ManufacturingOrder $o) => $this->row($o)),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(ManufacturingOrder $manufacturingOrder): JsonResponse
    {
        $manufacturingOrder->load(['vendor:id,name', 'subs' => fn ($q) => $q->orderBy('sub_no')]);

        return response()->json(['data' => $this->row($manufacturingOrder) + [
            'subs' => $manufacturingOrder->subs->map(fn ($s) => [
                'id' => $s->id, 'sub_no' => $s->sub_no, 'process' => $s->process,
                'serial_no' => $s->serial_no_raw, 'status' => $s->status,
                'finish_date' => $s->finish_date?->toDateString(),
                'note_start' => $s->note_start, 'note_end' => $s->note_end,
            ]),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['nullable', 'in:ASSEMBLY,JASA,assembly,jasa'],
            'date' => ['nullable', 'date'],
            'product_name' => ['nullable', 'string', 'max:200'],
            'site_id' => ['required', 'integer', 'exists:sites,id'],
            'vendor_id' => ['nullable', 'integer', 'exists:vendors,id'],
        ]);

        return response()->json(['data' => $this->row($this->service->createOrder($request->user(), $data)->loadCount('subs'))], 201);
    }

    public function addSub(Request $request, ManufacturingOrder $manufacturingOrder): JsonResponse
    {
        $data = $request->validate([
            'process' => ['nullable', 'string', 'max:60'],
            'serial_no_raw' => ['nullable', 'string', 'max:80'],
            'npbg_id' => ['nullable', 'integer', 'exists:npbg,id'],
            'note_start' => ['nullable', 'string', 'max:2000'],
        ]);
        $this->service->addSub($manufacturingOrder, $data);

        return $this->show($manufacturingOrder->fresh());
    }

    public function completeSub(Request $request, ManufacturingOrderSub $sub): JsonResponse
    {
        $data = $request->validate([
            'finish_date' => ['nullable', 'date'],
            'note_end' => ['nullable', 'string', 'max:2000'],
            'ri_id' => ['nullable', 'integer', 'exists:receivings,id'],
        ]);
        $this->service->completeSub($sub, $data);

        return $this->show($sub->order()->first());
    }

    private function row(ManufacturingOrder $o): array
    {
        return [
            'id' => $o->id, 'number' => $o->number, 'kind' => $o->kind, 'status' => $o->status,
            'product_name' => $o->product_name, 'date' => $o->date?->toDateString(),
            'completed_at' => $o->completed_at?->toDateString(),
            'vendor_name' => $o->relationLoaded('vendor') ? $o->vendor?->name : null,
            'subs_count' => $o->subs_count, 'created_at' => $o->created_at,
        ];
    }
}
