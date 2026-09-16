<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseProposalAmendment extends Model
{
    protected $table = 'purchase_proposal_amendments';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'qty_before' => 'float', 'qty_after' => 'float'];
    }

    public function ppb()
    {
        return $this->belongsTo(PurchaseProposal::class, 'ppb_id');
    }

    public function ppbItem()
    {
        return $this->belongsTo(PurchaseProposalItem::class, 'ppb_item_id');
    }
}
