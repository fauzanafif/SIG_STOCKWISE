<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * RI — mirror of Accurate APINV (header) + APITMDET (item lines), one row per
 * line. Populated exclusively by Sync Accurate (App\Services\Accurate\AccurateSyncService);
 * read-only, same as Ppb.
 */
class Ri extends Model
{
    protected $table = 'ri';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'tgl_ri' => 'date',
            'shipdate' => 'date',
            'kuantitas' => 'float',
            'harga_satuan' => 'float',
            'accurate_synced_at' => 'datetime',
        ];
    }

    /** The PO line this RI line received against — Accurate's own APITMDET.POID/POSEQ chain (not every RI line has one: internal stock-take style receipts have no PO). */
    public function poItem()
    {
        return $this->belongsTo(PurchaseOrderItem::class, 'accurate_po_item_id');
    }

    /**
     * Resolved vendor — same `vendors` row PO sync creates/uses for the same
     * Accurate PERSONDATA. Named vendorRecord() (not vendor()) because
     * `vendor` is already a plain string column on this model; a same-named
     * relation method would be permanently shadowed by that column and never
     * fire.
     */
    public function vendorRecord()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }
}
