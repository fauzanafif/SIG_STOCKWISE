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
            // DATA.xlsx DATABASE UTAMA: Kategori Induk/Anak 1/Anak 2/Anak 3 — dipecah dari path.
            'category_breakdown' => $this->whenLoaded('category', function () {
                $segments = array_pad(explode(' > ', (string) $this->category?->path), 4, null);

                return [
                    'induk' => $segments[0], 'anak_1' => $segments[1],
                    'anak_2' => $segments[2], 'anak_3' => $segments[3],
                ];
            }),
            'unit' => $this->whenLoaded('unit', fn () => $this->unit?->only('id', 'code', 'name')),
            'default_warehouse_id' => $this->default_warehouse_id,
            'default_warehouse' => $this->whenLoaded('defaultWarehouse', fn () => $this->defaultWarehouse?->only('id', 'code', 'name')),
            'default_location_id' => $this->default_location_id,
            'default_location' => $this->whenLoaded('defaultLocation', fn () => $this->defaultLocation?->only('id', 'code')),
            'blueprint_img_path' => $this->blueprint_img_path,
            'blueprint_pdf_path' => $this->blueprint_pdf_path,
            'blueprint_3d_ref' => $this->blueprint_3d_ref,
            // Excel lama: kolom "Nama Alias" bukan alias sungguhan (selalu "Tidak" — lihat
            // docs/excel-data-mapping.md §DATA.xlsx col 8). Alias asli ada di item_aliases.
            'alias_name' => 'Tidak',
            'aliases' => $this->whenLoaded('aliases', fn () => $this->aliases->pluck('alias_description')),
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
