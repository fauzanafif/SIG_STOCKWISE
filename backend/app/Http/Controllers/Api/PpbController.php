<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PpbResource;
use App\Models\Ppb;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PPB — Accurate REQUISITION/REQUISITIONDET mirror. Read-only: rows only ever
 * come from Sync Accurate (App\Services\Accurate\AccurateSyncService).
 */
class PpbController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Ppb::query();

        if ($s = $request->string('search')->trim()->value()) {
            $query->where(function ($q) use ($s) {
                $q->where('no_ppb', 'like', "%{$s}%")
                    ->orWhere('kode_barang', 'like', "%{$s}%")
                    ->orWhere('deskripsi_barang', 'like', "%{$s}%")
                    ->orWhere('peminta', 'like', "%{$s}%")
                    ->orWhere('divisi', 'like', "%{$s}%");
            });
        }

        $request->whenFilled('status', fn ($v) => $query->where('status', strtoupper((string) $v)));
        $request->whenFilled('divisi', fn ($v) => $query->where('divisi', $v));
        $request->whenFilled('date_from', fn ($v) => $query->whereDate('tgl_ppb', '>=', $v));
        $request->whenFilled('date_to', fn ($v) => $query->whereDate('tgl_ppb', '<=', $v));

        $query->orderByDesc('tgl_ppb')->orderByDesc('id');

        $page = $query->paginate(min((int) $request->integer('per_page', 20), 100));

        return response()->json([
            'data' => PpbResource::collection($page->getCollection())->resolve(),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(),
                'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(Ppb $ppb): JsonResponse
    {
        $ppb->load('poItems:id,accurate_ppb_id,purchase_order_id,qty,qty_received', 'poItems.purchaseOrder:id,number');

        return response()->json(['data' => (new PpbResource($ppb))->resolve() + [
            // Accurate's own PODET.REQID/REQSEQ chain — which PO lines were raised from this PPB line.
            'purchased_via' => $ppb->poItems->map(fn ($i) => [
                'purchase_order_id' => $i->purchase_order_id, 'number' => $i->purchaseOrder?->number,
                'qty' => $i->qty, 'qty_received' => $i->qty_received,
            ]),
        ]]);
    }
}
