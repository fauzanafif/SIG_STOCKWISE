<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Request\StoreMaterialRequest;
use App\Http\Resources\MaterialRequestResource;
use App\Models\MaterialRequest;
use App\Services\RequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MaterialRequestController extends Controller
{
    public function __construct(private readonly RequestService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $query = MaterialRequest::query()
            ->with(['requester:id,name', 'department:id,name', 'site:id,code'])
            ->withCount('items')
            ->latest();

        if (! $user->hasPermission('request.view')) {
            $query->where('requester_id', $user->id);
        }

        $request->whenFilled('status', fn ($v) => $query->where('status', strtoupper((string) $v)));
        $request->whenFilled('mine', fn () => $query->where('requester_id', $user->id));
        if ($s = $request->string('search')->trim()->value()) {
            $query->where(fn ($q) => $q->where('number', 'like', "%{$s}%")->orWhere('purpose', 'like', "%{$s}%"));
        }

        return MaterialRequestResource::collection(
            $query->paginate(min((int) $request->integer('per_page', 20), 100))->withQueryString()
        );
    }

    public function store(StoreMaterialRequest $request): JsonResponse
    {
        $req = $this->service->create($request->user(), $request->validated());

        return (new MaterialRequestResource($req->load('items.item:id,code', 'items.unit:id,code')))
            ->response()->setStatusCode(201);
    }

    public function show(Request $request, MaterialRequest $materialRequest): MaterialRequestResource
    {
        $this->authorizeView($request, $materialRequest);

        return new MaterialRequestResource($materialRequest->load([
            'items.item:id,code', 'items.unit:id,code', 'requester:id,name',
            'department:id,name', 'site:id,code,name', 'reviewer:id,name',
        ]));
    }

    public function update(StoreMaterialRequest $request, MaterialRequest $materialRequest): MaterialRequestResource
    {
        $this->authorizeOwner($request, $materialRequest);
        $req = $this->service->update($materialRequest, $request->validated());

        return new MaterialRequestResource($req->load('items.item:id,code', 'items.unit:id,code'));
    }

    public function submit(Request $request, MaterialRequest $materialRequest): MaterialRequestResource
    {
        $this->authorizeOwner($request, $materialRequest);

        return $this->fresh($this->service->submit($materialRequest));
    }

    public function review(Request $request, MaterialRequest $materialRequest): MaterialRequestResource
    {
        return $this->fresh($this->service->review($materialRequest, $request->user()));
    }

    public function physicalCheck(Request $request, MaterialRequest $materialRequest, int $item): MaterialRequestResource
    {
        $data = $request->validate([
            'status' => ['required', 'in:VERIFIED_MATCH,VERIFIED_MISMATCH,NOT_CHECKED'],
            'qty' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $line = $materialRequest->items()->findOrFail($item);
        $this->service->physicalCheck($line, $request->user(), $data['status'], $data['qty'] ?? null, $data['note'] ?? null);

        return $this->fresh($materialRequest);
    }

    public function reserve(Request $request, MaterialRequest $materialRequest): MaterialRequestResource
    {
        return $this->fresh($this->service->reserve($materialRequest, $request->user()));
    }

    public function needPurchase(Request $request, MaterialRequest $materialRequest): MaterialRequestResource
    {
        // PPB generation lands in PHASE 7; for now flag the short lines + status.
        $materialRequest->items()
            ->whereIn('line_status', ['PENDING', 'PARTIAL'])
            ->update(['line_status' => 'NEED_PURCHASE']);
        $materialRequest->update(['status' => 'NEED_PURCHASE']);

        return $this->fresh($materialRequest);
    }

    public function cancel(Request $request, MaterialRequest $materialRequest): MaterialRequestResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        if (! $request->user()->hasPermission('request.cancel_any')) {
            $this->authorizeOwner($request, $materialRequest);
        }

        return $this->fresh($this->service->cancel($materialRequest, $request->user(), $data['reason']));
    }

    // ------------------------------------------------------------------

    private function fresh(MaterialRequest $req): MaterialRequestResource
    {
        return new MaterialRequestResource($req->load('items.item:id,code', 'items.unit:id,code'));
    }

    private function authorizeView(Request $request, MaterialRequest $req): void
    {
        $user = $request->user();
        if (! $user->hasPermission('request.view') && $req->requester_id !== $user->id) {
            abort(403, 'This action is unauthorized.');
        }
    }

    private function authorizeOwner(Request $request, MaterialRequest $req): void
    {
        if ($req->requester_id !== $request->user()->id
            && ! $request->user()->hasPermission('request.cancel_any')) {
            abort(403, 'Hanya pemilik request yang bisa mengubahnya.');
        }
    }
}
