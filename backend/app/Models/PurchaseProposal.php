<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Internal purchase-proposal workflow (DRAFT→SUBMITTED→...→COMPLETED), generated
 * from MaterialRequest shortages or created manually by Purchasing.
 *
 * Not the same thing as Npbg — that model mirrors Accurate's real REQUISITION
 * document, which the company also calls "PPB". This model used to be named
 * Ppb (table `ppb`); renamed to free that name for the Accurate mirror.
 */
class PurchaseProposal extends Model
{
    use HasFactory;

    protected $table = 'purchase_proposals';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'approved_at' => 'datetime'];
    }

    public function items()
    {
        return $this->hasMany(PurchaseProposalItem::class, 'ppb_id');
    }

    public function amendments()
    {
        return $this->hasMany(PurchaseProposalAmendment::class, 'ppb_id');
    }

    public function sourceRequest()
    {
        return $this->belongsTo(MaterialRequest::class, 'source_request_id');
    }

    public function site()
    {
        return $this->belongsTo(Site::class);
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }
}
