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
}
