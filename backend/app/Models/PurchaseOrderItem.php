<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseOrderItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['qty' => 'float', 'unit_price' => 'float', 'line_total' => 'float', 'qty_received' => 'float'];
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    public function ppbItem()
    {
        return $this->belongsTo(PurchaseProposalItem::class, 'ppb_item_id');
    }

    /** The Accurate PPB (REQUISITIONDET) line this PO line was raised from — chain PPB->PO from Accurate's own PODET.REQID/REQSEQ, not the internal usulan-pembelian workflow above. */
    public function accuratePpb()
    {
        return $this->belongsTo(Ppb::class, 'accurate_ppb_id');
    }

    /** RI (APITMDET) lines that fulfilled this PO line — Accurate's own APITMDET.POID/POSEQ chain. */
    public function riLines()
    {
        return $this->hasMany(Ri::class, 'accurate_po_item_id');
    }
}
