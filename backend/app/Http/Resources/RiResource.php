<?php

namespace App\Http\Resources;

use App\Models\Ri;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Ri */
class RiResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'no_ri' => $this->no_ri,
            'tgl_ri' => $this->tgl_ri?->toDateString(),
            'divisi' => $this->divisi,
            'vendor' => $this->vendor,
            'no_po' => $this->no_po,
            'shipdate' => $this->shipdate?->toDateString(),
            'kode_barang' => $this->kode_barang,
            'deskripsi_barang' => $this->deskripsi_barang,
            'kuantitas' => $this->kuantitas,
            'satuan' => $this->satuan,
            'harga_satuan' => $this->harga_satuan,
            'pemeriksa' => $this->pemeriksa,
            'keterangan' => $this->keterangan,
            'accurate_synced_at' => $this->accurate_synced_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
