<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\NpbgResource;
use App\Models\Npbg;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * NPBG — Accurate ARINV/ARINVDET mirror. Read-mostly: rows only ever come from
 * Sync Accurate (App\Services\Accurate\AccurateSyncService); update() only ever
 * touches the Stockwise-owned enrichment fields (Npbg::STOCKWISE_OWNED_FIELDS).
 */
class NpbgController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Npbg::query()->withCount('verifications')->with('latestVerification');

        if ($s = $request->string('search')->trim()->value()) {
            $query->where(function ($q) use ($s) {
                $q->where('no_npbg', 'like', "%{$s}%")
                    ->orWhere('kode_barang', 'like', "%{$s}%")
                    ->orWhere('deskripsi_barang', 'like', "%{$s}%")
                    ->orWhere('peminta', 'like', "%{$s}%")
                    ->orWhere('divisi', 'like', "%{$s}%")
                    ->orWhere('pelanggan', 'like', "%{$s}%")
                    ->orWhere('keterangan', 'like', "%{$s}%");
            });
        }

        $request->whenFilled('tipe_npbg', fn ($v) => $query->where('tipe_npbg', $v));
        $request->whenFilled('klasifikasi', fn ($v) => $query->where('klasifikasi', $v));
        $request->whenFilled('divisi', fn ($v) => $query->where('divisi', $v));
        $request->whenFilled('pelanggan', fn ($v) => $query->where('pelanggan', $v));
        $request->whenFilled('date_from', fn ($v) => $query->whereDate('tgl_npbg', '>=', $v));
        $request->whenFilled('date_to', fn ($v) => $query->whereDate('tgl_npbg', '<=', $v));

        $query->orderByDesc('tgl_npbg')->orderByDesc('id');

        $page = $query->paginate(min((int) $request->integer('per_page', 20), 100));

        return response()->json([
            'data' => NpbgResource::collection($page->getCollection())->resolve(),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(),
                'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(Npbg $npbg): NpbgResource
    {
        return new NpbgResource($npbg->loadCount('verifications')->load('latestVerification'));
    }

    public function update(Request $request, Npbg $npbg): NpbgResource
    {
        $data = $request->validate([
            'tipe_npbg' => ['sometimes', 'nullable', 'string', 'max:30'],
            'klasifikasi' => ['sometimes', 'nullable', 'string', 'max:50'],
            'deskripsi' => ['sometimes', 'nullable', 'string', 'max:500'],
            'nama_proyek' => ['sometimes', 'nullable', 'string', 'max:200'],
            'no_seri_nopol' => ['sometimes', 'nullable', 'string', 'max:100'],
            'dikeluarkan_oleh' => ['sometimes', 'nullable', 'string', 'max:150'],
        ]);

        // Belt-and-braces: only Stockwise-owned columns can ever reach save(),
        // even if a future field gets added to the validation rules above.
        $npbg->fill(array_intersect_key($data, array_flip(Npbg::STOCKWISE_OWNED_FIELDS)))->save();

        return new NpbgResource($npbg);
    }
}
