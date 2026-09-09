<?php

namespace App\Services;

use App\Models\Inventory;
use App\Models\StockAdjustment;
use App\Models\StockOpname;
use App\Models\StockOpnameItem;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\StockLedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Stock Opname — docs/status-flow.md §7, business-process.md BP-4, brief §I/§J/§AP.
 * Physical count -> admin review -> APPROVED creates a stock_adjustment +
 * STOCK_ADJUSTMENT movement (the ONLY way opname changes actual stock).
 */
class StockOpnameService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly StockLedgerService $ledger,
    ) {}

    /** @param list<int>|null $itemIds  null = FULL (all inventory in the warehouse) */
    public function schedule(User $user, int $warehouseId, string $date, string $type, ?array $itemIds): StockOpname
    {
        $warehouse = Warehouse::findOrFail($warehouseId);

        return DB::transaction(function () use ($user, $warehouse, $date, $type, $itemIds) {
            $opname = StockOpname::create([
                'number' => $this->numbers->next('SO', 'GD'),
                'warehouse_id' => $warehouse->id,
                'site_id' => $warehouse->site_id,
                'scheduled_date' => $date,
                'type' => $type,
                'status' => 'SCHEDULED',
                'created_by' => $user->id,
            ]);

            $rows = Inventory::query()->where('warehouse_id', $warehouse->id)
                ->when($itemIds, fn ($q) => $q->whereIn('item_id', $itemIds))
                ->get();

            foreach ($rows as $inv) {
                $opname->items()->create([
                    'item_id' => $inv->item_id,
                    'warehouse_id' => $warehouse->id,
                    'system_qty' => $inv->actual_qty,
                ]);
            }

            return $opname->load('items');
        });
    }

    public function start(StockOpname $opname, User $user): StockOpname
    {
        $this->assert($opname, ['SCHEDULED', 'RECOUNT_REQUIRED']);
        $opname->update(['status' => 'IN_PROGRESS', 'started_at' => now(), 'counted_by' => $user->id]);

        return $opname;
    }

    public function count(StockOpnameItem $line, float $physicalQty, ?string $note): StockOpnameItem
    {
        $this->assert($line->opname, ['IN_PROGRESS']);

        $line->update([
            'physical_qty' => $physicalQty,
            'note' => $note,
            'count_status' => 'COUNTED',
        ]);

        return $line;
    }

    public function submit(StockOpname $opname): StockOpname
    {
        $this->assert($opname, ['IN_PROGRESS']);

        $items = $opname->items()->get();

        if ($items->contains(fn ($i) => $i->physical_qty === null)) {
            throw ValidationException::withMessages([
                'items' => ['Semua baris harus diisi jumlah fisiknya sebelum submit.'],
            ]);
        }

        // TC-SO-002: selisih tanpa catatan -> submit ditolak
        $missingNote = $items->first(fn ($i) => abs((float) $i->difference) > 1e-6 && ! filled($i->note));
        if ($missingNote) {
            throw ValidationException::withMessages([
                'items' => ["Baris item #{$missingNote->item_id} selisih tapi tanpa catatan."],
            ]);
        }

        $opname->update(['status' => 'PENDING_REVIEW', 'submitted_at' => now()]);

        return $opname->fresh('items');
    }

    /**
     * Review the whole opname.
     *
     * @param  array<int, array{id:int, decision:string}>  $decisions  per stock_opname_item
     */
    public function review(StockOpname $opname, User $reviewer, array $decisions, ?string $note): StockOpname
    {
        $this->assert($opname, ['PENDING_REVIEW']);

        return DB::transaction(function () use ($opname, $reviewer, $decisions, $note) {
            $map = collect($decisions)->keyBy('id');
            $anyRecount = false;
            $batch = (string) Str::uuid();

            foreach ($opname->items()->get() as $line) {
                $decision = $map[$line->id]['decision'] ?? 'APPROVED';
                $line->update(['review_status' => $decision]);

                if ($decision === 'RECOUNT') {
                    $anyRecount = true;

                    continue;
                }
                if ($decision !== 'APPROVED') {
                    continue;
                }
                if (abs((float) $line->difference) < 1e-6) {
                    continue;
                }

                // APPROVED + selisih -> stock adjustment (TC-SO-004)
                $movement = $this->ledger->record([
                    'type' => 'STOCK_ADJUSTMENT',
                    'item_id' => $line->item_id,
                    'warehouse_id' => $line->warehouse_id,
                    'absolute' => (float) $line->physical_qty,
                    'reference' => $line,
                    'batch_uuid' => $batch,
                    'created_by' => $reviewer->id,
                    'note' => "Opname {$opname->number}: {$line->note}",
                ]);

                StockAdjustment::create([
                    'stock_opname_item_id' => $line->id,
                    'item_id' => $line->item_id,
                    'warehouse_id' => $line->warehouse_id,
                    'qty_before' => $movement->actual_before,
                    'qty_after' => $movement->actual_after,
                    'difference' => $movement->actual_after - $movement->actual_before,
                    'reason' => $line->note ?? 'Stock opname',
                    'stock_movement_id' => $movement->id,
                    'approved_by' => $reviewer->id,
                    'approved_at' => now(),
                    'created_by' => $reviewer->id,
                ]);
            }

            $opname->update([
                'status' => $anyRecount ? 'RECOUNT_REQUIRED' : 'COMPLETED',
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_note' => $note,
            ]);

            return $opname->fresh('items');
        });
    }

    /** @param list<string> $allowed */
    private function assert(StockOpname $opname, array $allowed): void
    {
        if (! in_array($opname->status, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => ["Aksi tidak valid untuk status opname {$opname->status}."],
            ]);
        }
    }
}
