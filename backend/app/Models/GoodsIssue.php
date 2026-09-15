<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Goods issued against a warehouse reservation — pickup-from-request workflow
 * (DRAFT -> PREPARING -> READY_TO_PICKUP -> PICKED_UP -> COMPLETED). Formerly
 * named `Npbg`; renamed so `npbg`/App\Models\Npbg can represent the real NPBG
 * (Accurate ARINV/ARINVDET mirror, see App\Models\Npbg).
 */
class GoodsIssue extends Model
{
    use HasFactory;

    protected $table = 'goods_issues';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'picked_up_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        // goods_issue_items still physically uses the `npbg_id` FK column
        // (kept as-is when the table was renamed — see the migration).
        return $this->hasMany(GoodsIssueItem::class, 'npbg_id');
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(MaterialRequest::class, 'material_request_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
