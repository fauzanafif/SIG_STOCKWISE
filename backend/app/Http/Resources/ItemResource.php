<?php

namespace App\Http\Resources;

use App\Models\Item;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Item */
class ItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'description' => $this->description,
            'item_type' => $this->item_type,
            'needs_blueprint' => $this->needs_blueprint,
            'lead_time_days' => $this->lead_time_days,
            'is_active' => $this->is_active,
            'category' => $this->whenLoaded('category', fn () => $this->category ? [
                'id' => $this->category->id,
                'name' => $this->category->name,
                'path' => $this->category->path,
            ] : null),
            'unit' => $this->whenLoaded('unit', fn () => $this->unit?->only('id', 'code', 'name')),
            'default_warehouse_id' => $this->default_warehouse_id,
            'default_location_id' => $this->default_location_id,
            'blueprint_3d_ref' => $this->blueprint_3d_ref,
            'safety_stock' => $this->whenLoaded('effectiveSafetyStock', fn () => $this->effectiveSafetyStock?->safety_stock),
            'analysis' => $this->whenLoaded('snapshot', fn () => $this->snapshot ? [
                'actual' => $this->snapshot->actual,
                'reserved' => $this->snapshot->reserved,
                'available' => $this->snapshot->available,
                'stock_known' => $this->snapshot->stock_known,
                'safety_stock' => $this->snapshot->safety_stock,
                'selisih' => $this->snapshot->selisih,
                'status' => $this->snapshot->status,
                'deficit' => $this->snapshot->deficit,
                'priority_score' => $this->snapshot->priority_score,
                'priority_level' => $this->snapshot->priority_level,
                'recommendation' => $this->snapshot->recommendation,
                'recommended_qty' => $this->snapshot->recommended_qty,
            ] : null),
            'inventory' => $this->whenLoaded('inventory', fn () => $this->inventory->map(fn ($i) => [
                'warehouse_id' => $i->warehouse_id,
                'actual_qty' => $i->actual_qty,
                'reserved_qty' => $i->reserved_qty,
                'available_qty' => $i->available_qty,
                'stock_known' => $i->stock_known,
            ])),
        ];
    }
}
