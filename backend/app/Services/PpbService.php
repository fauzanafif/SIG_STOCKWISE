<?php

namespace App\Services;

use App\Models\InventoryAnalysisRun;
use App\Models\Item;
use App\Models\MaterialRequest;
use App\Models\Ppb;
use App\Models\User;
use App\Services\Inventory\InventoryAnalyzer;
use App\Services\Inventory\StockwiseEngine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * PPB (Permintaan Pembelian Barang) — docs/status-flow.md §3, brief §M.
 * DRAFT -> SUBMITTED -> REVIEW -> APPROVED -> PURCHASING -> ORDERED -> PARTIAL_RECEIVED -> RECEIVED -> COMPLETED.
 */
class PpbService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly StockwiseEngine $engine,
        private readonly InventoryAnalyzer $analyzer,
    ) {}

    public function createFromRequest(MaterialRequest $request, User $user): Ppb
    {
        $lines = $request->items()
            ->whereIn('line_status', ['NEED_PURCHASE', 'PARTIAL'])
            ->where('qty_to_purchase', '>', 0)
            ->with('item.effectiveSafetyStock')
            ->get();

        if ($lines->isEmpty()) {
            throw ValidationException::withMessages(['items' => ['Tidak ada baris yang perlu dibeli.']]);
        }

        return DB::transaction(function () use ($request, $user, $lines) {
            $ppb = $this->makeHeader($user, [
                'requester_id' => $request->requester_id,
                'department_id' => $request->department_id,
                'site_id' => $request->site_id,
                'source_request_id' => $request->id,
            ]);

            foreach ($lines as $line) {
                $this->addLine($ppb, [
                    'item_id' => $line->item_id,
                    'material_request_item_id' => $line->id,
                    'description_raw' => $line->description_raw,
                    'qty' => $line->qty_to_purchase,
                    'unit_id' => $line->unit_id,
                    'shortage_qty' => $line->qty_to_purchase,
                ]);
            }

            $request->update(['ppb_no' => $request->ppb_no ?: $ppb->number]);

            return $ppb->load('items');
        });
    }

    /** @param array{items:array<int,array{item_id?:?int, description_raw?:string, qty:float, unit_id?:?int}>, notes?:?string} $data */
    public function createManual(User $user, array $data): Ppb
    {
        return DB::transaction(function () use ($user, $data) {
            $ppb = $this->makeHeader($user, [
                'requester_id' => $user->id,
                'department_id' => $user->employee?->department_id,
                'site_id' => $user->site_id,
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($data['items'] as $row) {
                $item = ! empty($row['item_id']) ? Item::find($row['item_id']) : null;
                $this->addLine($ppb, [
                    'item_id' => $item?->id,
                    'description_raw' => $row['description_raw'] ?? $item?->description ?? '-',
                    'qty' => $row['qty'],
                    'unit_id' => $row['unit_id'] ?? $item?->unit_id,
                ]);
            }

            return $ppb->load('items');
        });
    }

    public function submit(Ppb $ppb): Ppb
    {
        $this->assert($ppb, ['DRAFT']);
        abort_if($ppb->items()->count() === 0, 422, 'PPB kosong.');
        $ppb->update(['status' => 'SUBMITTED']);

        return $ppb;
    }

    public function review(Ppb $ppb): Ppb
    {
        $this->assert($ppb, ['SUBMITTED', 'REVIEW']);
        $ppb->update(['status' => 'REVIEW']);

        return $ppb;
    }

    public function approve(Ppb $ppb, User $user): Ppb
    {
        $this->assert($ppb, ['SUBMITTED', 'REVIEW']);
        $ppb->update([
            'status' => 'APPROVED',
            'approved_by' => $user->id,
            'approved_at' => now(),
        ]);
        $ppb->items()->update(['line_status' => 'APPROVED']);

        return $ppb->fresh('items');
    }

    public function reject(Ppb $ppb, string $reason): Ppb
    {
        $this->assert($ppb, ['SUBMITTED', 'REVIEW']);
        $ppb->update(['status' => 'CANCELLED', 'notes' => trim(($ppb->notes ?? '')."\nDitolak: {$reason}")]);

        return $ppb;
    }

    public function amend(Ppb $ppb, User $user, ?int $ppbItemId, string $type, ?float $qtyAfter, string $reason): Ppb
    {
        abort_if(in_array($ppb->status, ['RECEIVED', 'COMPLETED', 'CANCELLED'], true), 422, 'PPB sudah selesai.');

        return DB::transaction(function () use ($ppb, $user, $ppbItemId, $type, $qtyAfter, $reason) {
            $line = $ppbItemId ? $ppb->items()->findOrFail($ppbItemId) : null;

            $ppb->amendments()->create([
                'ppb_item_id' => $line?->id,
                'date' => now()->toDateString(),
                'type' => $type,
                'qty_before' => $line?->qty,
                'qty_after' => $qtyAfter,
                'reason' => $reason,
                'created_by' => $user->id,
            ]);

            if ($line && $type === 'AMEND' && $qtyAfter !== null) {
                $line->update(['qty' => $qtyAfter]);
            }
            if ($line && $type === 'CLOSE') {
                $line->update(['line_status' => 'CLOSED']);
            }
            if (! $line && $type === 'CLOSE') {
                $ppb->items()->update(['line_status' => 'CLOSED']);
                $ppb->update(['status' => 'CANCELLED']);
            }

            return $ppb->fresh('items', 'amendments');
        });
    }

    // ------------------------------------------------------------------

    private function makeHeader(User $user, array $attrs): Ppb
    {
        $date = now();
        $prefix = $attrs['prefix'] ?? 'NA';
        $number = $this->numbers->next('PPB', $prefix, $date);
        [, , $yy, , $seq] = explode('/', $number);

        return Ppb::create(array_merge([
            'number' => $number,
            'prefix' => $prefix,
            'year' => (int) $yy,
            'month' => (int) $date->format('n'),
            'sequence' => (int) $seq,
            'date' => $date->toDateString(),
            'status' => 'DRAFT',
            'created_by' => $user->id,
        ], $attrs));
    }

    private function addLine(Ppb $ppb, array $data): void
    {
        $analysis = null;
        if (! empty($data['item_id'])) {
            $item = Item::with('effectiveSafetyStock')->find($data['item_id']);
            $available = (float) ($item?->inventory()->sum('actual_qty') - $item?->inventory()->sum('reserved_qty'));
            $safety = (float) ($item?->effectiveSafetyStock?->safety_stock ?? 0);
            $run = InventoryAnalysisRun::latest('computed_at')->first();
            $analysis = $this->engine->analyze(
                sisaStok: $available,
                safetyStock: $safety,
                leadTimeDays: (int) ($item?->lead_time_days ?? 0),
                medianDeficitTidakAman: (float) ($run?->median_deficit ?? 0),
                leadTimeThreshold: (float) ($run?->lead_time_threshold ?? 14),
                uom: $item?->unit?->code,
            );
        }

        $ppb->items()->create([
            'item_id' => $data['item_id'] ?? null,
            'material_request_item_id' => $data['material_request_item_id'] ?? null,
            'description_raw' => $data['description_raw'],
            'qty' => $data['qty'],
            'unit_id' => $data['unit_id'] ?? null,
            'shortage_qty' => $data['shortage_qty'] ?? null,
            'safety_stock_snapshot' => $analysis?->safetyStock,
            'deficit_snapshot' => $analysis?->deficit,
            'priority_score_snapshot' => $analysis ? round($analysis->priorityScore, 2) : null,
            'priority_level_snapshot' => $analysis?->priorityLevel,
            'line_status' => 'PENDING',
        ]);
    }

    /** @param list<string> $allowed */
    private function assert(Ppb $ppb, array $allowed): void
    {
        if (! in_array($ppb->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => ["Aksi tidak valid untuk status PPB {$ppb->status}."]]);
        }
    }
}
