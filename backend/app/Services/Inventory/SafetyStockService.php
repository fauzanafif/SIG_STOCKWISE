<?php

namespace App\Services\Inventory;

use App\Models\ItemSafetyStock;
use Illuminate\Support\Facades\DB;

/**
 * Manual CRUD for item_safety_stocks — admin input replacing the "SAFETY STOCK *" Excel
 * sheets. Formulas replicated verbatim from the confirmed DATA.xlsx sheet formulas (see
 * docs/excel-data-mapping.md / LegacyExcelExportService): sqrt_lt = SQRT(lead_time/30),
 * safety_stock = ROUNDUP((2.33*avg_usage_1m)*sqrt_lt, 0), min_pr = ROUNDUP((avg_usage_1m*lead_time/30)+1, 0).
 */
class SafetyStockService
{
    public function create(array $data): ItemSafetyStock
    {
        return DB::transaction(function () use ($data) {
            $row = new ItemSafetyStock($this->fillable($data));
            $this->applyFormula($row);
            $row->is_effective = ! ItemSafetyStock::query()->where('item_id', $data['item_id'])->exists();
            $row->needs_review = ! $row->is_effective;
            $row->save();

            return $row;
        });
    }

    public function update(ItemSafetyStock $safetyStock, array $data): ItemSafetyStock
    {
        $safetyStock->fill($this->fillable($data));
        $this->applyFormula($safetyStock);
        $safetyStock->save();

        return $safetyStock;
    }

    public function delete(ItemSafetyStock $safetyStock): void
    {
        $itemId = $safetyStock->item_id;
        $wasEffective = $safetyStock->is_effective;
        $safetyStock->delete();

        if ($wasEffective) {
            $next = ItemSafetyStock::query()->where('item_id', $itemId)->orderByDesc('safety_stock')->first();
            $next?->update(['is_effective' => true, 'needs_review' => false]);
        }
    }

    public function resolveConflict(ItemSafetyStock $safetyStock): ItemSafetyStock
    {
        DB::transaction(function () use ($safetyStock) {
            ItemSafetyStock::query()->where('item_id', $safetyStock->item_id)
                ->where('id', '!=', $safetyStock->id)
                ->update(['is_effective' => false, 'needs_review' => true]);

            $safetyStock->update(['is_effective' => true, 'needs_review' => false]);
        });

        return $safetyStock->fresh();
    }

    private function fillable(array $data): array
    {
        return array_intersect_key($data, array_flip([
            'item_id', 'source_category', 'period_label',
            'avg_usage_1m', 'avg_usage_3m', 'avg_usage_6m', 'avg_usage_12m',
            'lead_time_days', 'effective_date', 'note',
        ]));
    }

    private function applyFormula(ItemSafetyStock $row): void
    {
        $avg1 = (float) ($row->avg_usage_1m ?? 0);
        $leadTime = (int) ($row->lead_time_days ?? 0);

        $sqrtLt = sqrt($leadTime / 30);
        $row->sqrt_lt = round($sqrtLt, 4);
        $row->safety_stock = (float) ceil((2.33 * $avg1) * $sqrtLt);
        $row->min_pr = (float) ceil(($avg1 * $leadTime / 30) + 1);
    }
}
