<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\NpbgResource;
use App\Models\MaterialRequest;
use App\Models\Npbg;
use App\Services\NpbgService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class NpbgController extends Controller
{
    public function __construct(private readonly NpbgService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $query = Npbg::query()
            ->with(['warehouse:id,code', 'request:id,number'])
            ->withCount('items')
            ->latest();

        if (! $user->hasPermission('npbg.view')) {
            $query->where('requester_id', $user->id);
        }
        $request->whenFilled('status', fn ($v) => $query->where('status', strtoupper((string) $v)));
        $request->whenFilled('classification', fn ($v) => $query->where('classification', strtoupper((string) $v)));
        if ($s = $request->string('search')->trim()->value()) {
            $query->where('number', 'like', "%{$s}%");
        }

        return NpbgResource::collection(
            $query->paginate(min((int) $request->integer('per_page', 20), 100))->withQueryString()
        );
    }

    public function show(Request $request, Npbg $npbg): NpbgResource
    {
        $user = $request->user();
        if (! $user->hasPermission('npbg.view') && $npbg->requester_id !== $user->id) {
            abort(403, 'This action is unauthorized.');
        }

        return new NpbgResource($npbg->load('items.item:id,code', 'items.unit:id,code', 'warehouse', 'request'));
    }

    public function storeFromRequest(Request $request): JsonResponse
    {
        $data = $request->validate(['material_request_id' => ['required', 'integer', 'exists:material_requests,id']]);
        $mr = MaterialRequest::findOrFail($data['material_request_id']);

        $npbg = $this->service->createFromRequest($mr, $request->user());

        return (new NpbgResource($npbg->load('items.item:id,code', 'items.unit:id,code')))
            ->response()->setStatusCode(201);
    }

    public function storeManual(Request $request): JsonResponse
    {
        $data = $request->validate([
            'classification' => ['sometimes', 'string', 'max:25'],
            'type' => ['sometimes', 'in:PENJUALAN,NON_PENJUALAN'],
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'requester_name' => ['nullable', 'string', 'max:150'],
            'customer_name' => ['nullable', 'string', 'max:200'],
            'project_name' => ['nullable', 'string', 'max:200'],
            'asset_ref' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'integer', 'exists:items,id'],
            'items.*.qty' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'items.*.note' => ['nullable', 'string', 'max:255'],
        ]);
        $data['site_id'] = $request->user()->site_id;

        $npbg = $this->service->createManual($request->user(), $data);

        return (new NpbgResource($npbg->load('items.item:id,code', 'items.unit:id,code')))
            ->response()->setStatusCode(201);
    }

    public function update(Request $request, Npbg $npbg): NpbgResource
    {
        $data = $request->validate([
            'classification' => ['sometimes', 'string', 'max:25'],
            'customer_name' => ['sometimes', 'nullable', 'string', 'max:200'],
            'project_name' => ['sometimes', 'nullable', 'string', 'max:200'],
            'asset_ref' => ['sometimes', 'nullable', 'string', 'max:100'],
            'requester_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'items' => ['sometimes', 'array', 'min:1'],
            'items.*.item_id' => ['nullable', 'integer', 'exists:items,id'],
            'items.*.description_raw' => ['required_without:items.*.item_id', 'nullable', 'string', 'max:400'],
            'items.*.qty' => ['required_with:items', 'numeric', 'gt:0'],
            'items.*.unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'items.*.note' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->fresh($this->service->update($npbg, $data));
    }

    public function prepare(Npbg $npbg): NpbgResource
    {
        return $this->fresh($this->service->prepare($npbg));
    }

    public function ready(Npbg $npbg): NpbgResource
    {
        return $this->fresh($this->service->ready($npbg));
    }

    public function pickup(Request $request, Npbg $npbg): NpbgResource
    {
        $data = $request->validate([
            'picked_up_by' => ['required', 'string', 'max:150'],
            'signature' => ['nullable', 'string'],
        ]);

        return $this->fresh(
            $this->service->pickup($npbg, $request->user(), $data['picked_up_by'], $data['signature'] ?? null)
        );
    }

    public function cancel(Request $request, Npbg $npbg): NpbgResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->fresh($this->service->cancel($npbg, $request->user(), $data['reason']));
    }

    private function fresh(Npbg $npbg): NpbgResource
    {
        return new NpbgResource($npbg->load('items.item:id,code', 'items.unit:id,code', 'warehouse'));
    }
}
