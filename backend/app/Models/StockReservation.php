<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockReservation extends Model
{
    protected $fillable = [
        'item_id', 'warehouse_id', 'material_request_id', 'material_request_item_id',
        'qty', 'status', 'reserved_by', 'reserved_at', 'released_at', 'expires_at', 'note',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'float',
            'reserved_at' => 'datetime',
            'released_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(MaterialRequest::class, 'material_request_id');
    }

    public function requestItem(): BelongsTo
    {
        return $this->belongsTo(MaterialRequestItem::class, 'material_request_item_id');
    }
}
