<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoodsIssueItem extends Model
{
    protected $table = 'goods_issue_items';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'qty' => 'float',
            'qty_issued' => 'float',
        ];
    }

    public function goodsIssue(): BelongsTo
    {
        return $this->belongsTo(GoodsIssue::class, 'npbg_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(StockReservation::class, 'stock_reservation_id');
    }

    public function requestItem(): BelongsTo
    {
        return $this->belongsTo(MaterialRequestItem::class, 'material_request_item_id');
    }
}
