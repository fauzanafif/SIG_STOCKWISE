<?php

namespace App\Http\Resources;

use App\Models\GoodsIssue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin GoodsIssue */
class GoodsIssueResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status,
            'type' => $this->type,
            'classification' => $this->classification,
            'date' => $this->date?->toDateString(),
            'material_request_id' => $this->material_request_id,
            'request_number' => $this->whenLoaded('request', fn () => $this->request?->number),
            'requester' => $this->requester_name ?? $this->whenLoaded('requester', fn () => $this->requester?->name),
            'warehouse' => $this->whenLoaded('warehouse', fn () => $this->warehouse?->only('id', 'code', 'name')),
            'customer_name' => $this->customer_name,
            'project_name' => $this->project_name,
            'asset_ref' => $this->asset_ref,
            'picked_up_by' => $this->picked_up_by,
            'picked_up_at' => $this->picked_up_at,
            'has_signature' => (bool) $this->signature_path,
            'cancel_reason' => $this->cancel_reason,
            'notes' => $this->notes,
            'created_at' => $this->created_at,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($l) => [
                'id' => $l->id,
                'item_id' => $l->item_id,
                'item_code' => $l->relationLoaded('item') ? $l->item?->code : null,
                'description' => $l->description_raw,
                'item_no' => $l->item_no,
                'qty' => $l->qty,
                'qty_issued' => $l->qty_issued,
                'unit' => $l->relationLoaded('unit') ? $l->unit?->code : null,
                'note' => $l->note,
            ])),
        ];
    }
}
