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
            // Resolved `vendors` row — same one PO sync creates/uses for this Accurate PERSONDATA (not every RI vendor is a real external vendor, e.g. internal stock-take entries; flagged needs_review on the vendor itself, not here).
            'vendor_id' => $this->vendor_id,
            'no_po' => $this->no_po,
            // Accurate's own APITMDET.POID/POSEQ chain — which PO line this RI line received against (resolved FK, not just the free-text no_po header field above).
            'source_po' => $this->whenLoaded('poItem', fn () => $this->poItem ? [
                'purchase_order_id' => $this->poItem->purchase_order_id,
                'purchase_order_item_id' => $this->poItem->id,
                'number' => $this->poItem->purchaseOrder?->number,
            ] : null),
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
