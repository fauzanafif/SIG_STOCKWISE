<?php

namespace App\Http\Resources;

use App\Models\Npbg;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Npbg */
class NpbgResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'no_npbg' => $this->no_npbg,
            'tgl_npbg' => $this->tgl_npbg?->toDateString(),
            'shipdate' => $this->shipdate?->toDateString(),
            'taxdate' => $this->taxdate?->toDateString(),
            'tipe_npbg' => $this->tipe_npbg,
            'klasifikasi' => $this->klasifikasi,
            'kode_barang' => $this->kode_barang,
            'deskripsi_barang' => $this->deskripsi_barang,
            'deskripsi' => $this->deskripsi,
            'kuantitas' => $this->kuantitas,
            'satuan' => $this->satuan,
            'peminta' => $this->peminta,
            'divisi' => $this->divisi,
            'pelanggan' => $this->pelanggan,
            'nama_proyek' => $this->nama_proyek,
            'no_seri_nopol' => $this->no_seri_nopol,
            'dikeluarkan_oleh' => $this->dikeluarkan_oleh,
            'keterangan' => $this->keterangan,
            'accurate_synced_at' => $this->accurate_synced_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'verifications_count' => $this->whenCounted('verifications'),
            'latest_verification_status' => $this->whenLoaded('latestVerification', fn () => $this->latestVerification?->status),
        ];
    }
}
