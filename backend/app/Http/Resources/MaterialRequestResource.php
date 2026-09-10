<?php

namespace App\Http\Resources;

use App\Models\MaterialRequest;
use App\Support\Network\IpClassifier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MaterialRequest */
class MaterialRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status,
            'purpose' => $this->purpose,
            'notes' => $this->notes,
            'request_date' => $this->request_date?->toDateString(),
            'work_location' => $this->work_location,
            'needed_date' => $this->needed_date?->toDateString(),
            // Diisi admin gudang, bukan peminta.
            'npbg_no' => $this->npbg_no,
            'ppb_no' => $this->ppb_no,
            'requester_name' => $this->requester_name,
            'requester_wa' => $this->requester_wa,
            // Lokasi permintaan berdasarkan IP.
            'request_ip' => $this->request_ip,
            'network_label' => $this->network_label,
            'network_label_text' => $this->network_label ? IpClassifier::label($this->network_label) : null,
            'requester' => [
                'id' => $this->requester_id,
                'name' => $this->whenLoaded('requester', fn () => $this->requester?->name),
            ],
            'department' => $this->whenLoaded('department', fn () => $this->department?->only('id', 'name')),
            'site' => $this->whenLoaded('site', fn () => $this->site?->only('id', 'code', 'name')),
            'reviewer' => $this->whenLoaded('reviewer', fn () => $this->reviewer?->only('id', 'name')),
            'submitted_at' => $this->submitted_at,
            'reviewed_at' => $this->reviewed_at,
            'completed_at' => $this->completed_at,
            'cancel_reason' => $this->cancel_reason,
            'created_at' => $this->created_at,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($line) => [
                'id' => $line->id,
                'item_id' => $line->item_id,
                'item_code' => $line->relationLoaded('item') ? $line->item?->code : null,
                'description' => $line->description_raw,
                'qty_requested' => $line->qty_requested,
                'unit' => $line->relationLoaded('unit') ? $line->unit?->code : null,
                'warehouse_id' => $line->warehouse_id,
                'system_stock_snapshot' => $line->system_stock_snapshot,
                'safety_stock_snapshot' => $line->safety_stock_snapshot,
                'projected_stock' => $line->projected_stock,
                'below_safety_flag' => $line->below_safety_flag,
                'warning' => $line->below_safety_flag
                    ? 'Request ini akan menyebabkan stok berada di bawah Safety Stock.'
                    : null,
                'physical_check_status' => $line->physical_check_status,
                'physical_check_qty' => $line->physical_check_qty,
                'physical_check_note' => $line->physical_check_note,
                'qty_approved' => $line->qty_approved,
                'qty_reserved' => $line->qty_reserved,
                'qty_to_purchase' => $line->qty_to_purchase,
                'line_status' => $line->line_status,
                'note' => $line->note,
            ])),
        ];
    }
}
