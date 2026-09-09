<?php

namespace App\Services\Inventory;

use App\Models\Inventory;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The single writer for stock. Every change is a `stock_movements` row + an
 * `inventory` update inside one DB transaction (docs/calculation-engine.md §6,
 * brief ATURAN MUTLAK 5–11). Nothing else may touch `inventory` directly.
 */
class StockLedgerService
{
    /** movement_type => direction applied to actual_qty */
    private const ACTUAL_DIRECTION = [
        'OPENING_BALANCE' => 1,
        'STOCK_IN' => 1,
        'RECEIVING' => 1,
        'RETURN' => 1,
        'TRANSFER_IN' => 1,
        'STOCK_OUT' => -1,
        'TRANSFER_OUT' => -1,
        'RESERVATION' => 0,
        'RELEASE_RESERVATION' => 0,
        'STOCK_ADJUSTMENT' => 0, // sets an absolute value, handled specially
    ];

    /**
     * Apply one movement.
     *
     * @param  array{
     *   type: string, item_id: int, warehouse_id: int, qty?: float,
     *   absolute?: float, reserve_delta?: float, reference?: Model|null,
     *   note?: string|null, created_by?: int|null, batch_uuid?: string|null
     * }  $data
     */
    public function record(array $data): StockMovement
    {
        $type = $data['type'];

        if (! array_key_exists($type, self::ACTUAL_DIRECTION)) {
            throw new RuntimeException("Unknown movement type: {$type}");
        }

        return DB::transaction(function () use ($data, $type) {
            /** @var Inventory $inv */
            $inv = Inventory::query()
                ->where('item_id', $data['item_id'])
                ->where('warehouse_id', $data['warehouse_id'])
                ->lockForUpdate()
                ->firstOrCreate(
                    ['item_id' => $data['item_id'], 'warehouse_id' => $data['warehouse_id']],
                    ['actual_qty' => 0, 'reserved_qty' => 0]
                );

            $actualBefore = (float) $inv->actual_qty;
            $reservedBefore = (float) $inv->reserved_qty;

            $reserveDelta = (float) ($data['reserve_delta'] ?? 0);
            $qty = (float) ($data['qty'] ?? abs($reserveDelta));

            if ($type === 'STOCK_ADJUSTMENT') {
                $actualAfter = (float) ($data['absolute'] ?? $actualBefore);
                $qty = abs($actualAfter - $actualBefore);
                $direction = $actualAfter <=> $actualBefore;
            } else {
                $direction = self::ACTUAL_DIRECTION[$type];
                $actualAfter = $actualBefore + ($direction * $qty);
            }

            $reservedAfter = $reservedBefore + $reserveDelta;

            if ($actualAfter < -1e-6) {
                throw new RuntimeException("Stock would go negative for item {$data['item_id']} @ warehouse {$data['warehouse_id']}");
            }
            if ($reservedAfter < -1e-6) {
                throw new RuntimeException('Reserved stock would go negative.');
            }
            if ($reservedAfter > $actualAfter + 1e-6) {
                throw new RuntimeException('Reserved stock would exceed actual stock.');
            }

            $inv->forceFill([
                'actual_qty' => round($actualAfter, 2),
                'reserved_qty' => round($reservedAfter, 2),
                'stock_known' => $inv->stock_known || $type === 'OPENING_BALANCE' || $type === 'STOCK_ADJUSTMENT',
                'last_movement_at' => now(),
                'last_counted_at' => $type === 'STOCK_ADJUSTMENT' ? now() : $inv->last_counted_at,
            ])->save();

            $reference = $data['reference'] ?? null;

            return StockMovement::create([
                'item_id' => $data['item_id'],
                'warehouse_id' => $data['warehouse_id'],
                'movement_type' => $type,
                'direction' => $direction,
                'qty' => round($qty, 2),
                'actual_before' => round($actualBefore, 2),
                'actual_after' => round($actualAfter, 2),
                'reserved_before' => round($reservedBefore, 2),
                'reserved_after' => round($reservedAfter, 2),
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
                'batch_uuid' => $data['batch_uuid'] ?? (string) Str::uuid(),
                'note' => $data['note'] ?? null,
                'created_by' => $data['created_by'] ?? null,
                'created_at' => now(),
            ]);
        });
    }

    public function openingBalance(int $itemId, int $warehouseId, float $qty, ?int $userId = null): StockMovement
    {
        return $this->record([
            'type' => 'OPENING_BALANCE',
            'item_id' => $itemId,
            'warehouse_id' => $warehouseId,
            'qty' => $qty,
            'note' => 'Opening balance',
            'created_by' => $userId,
        ]);
    }
}
