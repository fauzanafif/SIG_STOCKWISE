<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RiResource;
use App\Models\Ri;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * RI — Accurate APINV/APITMDET mirror. Read-only: rows only ever come from
 * Sync Accurate (App\Services\Accurate\AccurateSyncService).
 */
class RiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Ri::query()->with('poItem:id,purchase_order_id', 'poItem.purchaseOrder:id,number');

        if ($s = $request->string('search')->trim()->value()) {
            $query->where(function ($q) use ($s) {
                $q->where('no_ri', 'like', "%{$s}%")
                    ->orWhere('kode_barang', 'like', "%{$s}%")
                    ->orWhere('deskripsi_barang', 'like', "%{$s}%")
                    ->orWhere('vendor', 'like', "%{$s}%")
                    ->orWhere('pemeriksa', 'like', "%{$s}%")
                    ->orWhere('divisi', 'like', "%{$s}%");
            });
        }

        $request->whenFilled('divisi', fn ($v) => $query->where('divisi', $v));
        $request->whenFilled('date_from', fn ($v) => $query->whereDate('tgl_ri', '>=', $v));
        $request->whenFilled('date_to', fn ($v) => $query->whereDate('tgl_ri', '<=', $v));

        $query->orderByDesc('tgl_ri')->orderByDesc('id');

        $page = $query->paginate(min((int) $request->integer('per_page', 20), 100));

        return response()->json([
            'data' => RiResource::collection($page->getCollection())->resolve(),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(),
                'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(Ri $ri): RiResource
    {
        $ri->load('poItem:id,purchase_order_id', 'poItem.purchaseOrder:id,number');

        return new RiResource($ri);
    }
}
