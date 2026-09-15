<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\GoodsIssueResource;
use App\Models\GoodsIssue;
use App\Models\MaterialRequest;
use App\Services\GoodsIssueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Formerly NpbgController — see App\Models\GoodsIssue for why it was renamed. */
class GoodsIssueController extends Controller
{
    public function __construct(private readonly GoodsIssueService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $query = GoodsIssue::query()
            ->with(['warehouse:id,code', 'request:id,number'])
            ->withCount('items')
            ->latest();

        if (! $user->hasPermission('goods_issue.view')) {
            $query->where('requester_id', $user->id);
        }
        $request->whenFilled('status', fn ($v) => $query->where('status', strtoupper((string) $v)));
        $request->whenFilled('classification', fn ($v) => $query->where('classification', strtoupper((string) $v)));
        if ($s = $request->string('search')->trim()->value()) {
            $query->where('number', 'like', "%{$s}%");
        }

        return GoodsIssueResource::collection(
            $query->paginate(min((int) $request->integer('per_page', 20), 100))->withQueryString()
        );
    }

    public function show(Request $request, GoodsIssue $goodsIssue): GoodsIssueResource
    {
        $user = $request->user();
        if (! $user->hasPermission('goods_issue.view') && $goodsIssue->requester_id !== $user->id) {
            abort(403, 'This action is unauthorized.');
        }

        return new GoodsIssueResource($goodsIssue->load('items.item:id,code', 'items.unit:id,code', 'warehouse', 'request'));
    }

    public function storeFromRequest(Request $request): JsonResponse
    {
        $data = $request->validate(['material_request_id' => ['required', 'integer', 'exists:material_requests,id']]);
        $mr = MaterialRequest::findOrFail($data['material_request_id']);

        $goodsIssue = $this->service->createFromRequest($mr, $request->user());

        return (new GoodsIssueResource($goodsIssue->load('items.item:id,code', 'items.unit:id,code')))
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

        $goodsIssue = $this->service->createManual($request->user(), $data);

        return (new GoodsIssueResource($goodsIssue->load('items.item:id,code', 'items.unit:id,code')))
            ->response()->setStatusCode(201);
    }

    public function update(Request $request, GoodsIssue $goodsIssue): GoodsIssueResource
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

        return $this->fresh($this->service->update($goodsIssue, $data));
    }

    public function prepare(GoodsIssue $goodsIssue): GoodsIssueResource
    {
        return $this->fresh($this->service->prepare($goodsIssue));
    }

    public function ready(GoodsIssue $goodsIssue): GoodsIssueResource
    {
        return $this->fresh($this->service->ready($goodsIssue));
    }

    public function pickup(Request $request, GoodsIssue $goodsIssue): GoodsIssueResource
    {
        $data = $request->validate([
            'picked_up_by' => ['required', 'string', 'max:150'],
            'signature' => ['nullable', 'string'],
        ]);

        return $this->fresh(
            $this->service->pickup($goodsIssue, $request->user(), $data['picked_up_by'], $data['signature'] ?? null)
        );
    }

    public function cancel(Request $request, GoodsIssue $goodsIssue): GoodsIssueResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->fresh($this->service->cancel($goodsIssue, $request->user(), $data['reason']));
    }

    private function fresh(GoodsIssue $goodsIssue): GoodsIssueResource
    {
        return new GoodsIssueResource($goodsIssue->load('items.item:id,code', 'items.unit:id,code', 'warehouse'));
    }
}
