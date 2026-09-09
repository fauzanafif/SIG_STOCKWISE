<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaterialRequestItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'material_request_id', 'item_id', 'description_raw', 'qty_requested', 'unit_id',
        'warehouse_id', 'system_stock_snapshot', 'safety_stock_snapshot', 'projected_stock',
        'below_safety_flag', 'physical_check_status', 'physical_check_qty', 'physical_check_note',
        'physical_checked_by', 'qty_approved', 'qty_reserved', 'qty_to_purchase', 'qty_issued',
        'line_status', 'note',
    ];

    protected function casts(): array
    {
        return [
            'qty_requested' => 'float',
            'system_stock_snapshot' => 'float',
            'safety_stock_snapshot' => 'float',
            'projected_stock' => 'float',
            'below_safety_flag' => 'boolean',
            'physical_check_qty' => 'float',
            'qty_approved' => 'float',
            'qty_reserved' => 'float',
            'qty_to_purchase' => 'float',
            'qty_issued' => 'float',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(MaterialRequest::class, 'material_request_id');
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
}
