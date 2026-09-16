<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseProposalItem extends Model
{
    protected $table = 'purchase_proposal_items';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['qty' => 'float', 'qty_ordered' => 'float', 'qty_received' => 'float', 'shortage_qty' => 'float'];
    }

    public function ppb()
    {
        return $this->belongsTo(PurchaseProposal::class, 'ppb_id');
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    public function requestItem()
    {
        return $this->belongsTo(MaterialRequestItem::class, 'material_request_item_id');
    }
}
