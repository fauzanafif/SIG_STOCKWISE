<?php

namespace App\Services;

use App\Models\Inventory;
use App\Models\Item;
use App\Models\MaterialRequest;
use App\Models\MaterialRequestItem;
use App\Models\StockReservation;
use App\Models\User;
use App\Services\Inventory\StockLedgerService;
use App\Support\Network\IpClassifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * State machine for Material Requests (docs/status-flow.md §1, business-process.md BP-1).
 * DRAFT -> SUBMITTED -> UNDER_REVIEW -> (READY|PARTIAL|NEED_PURCHASE) -> RESERVED
 * PREPARING onwards is handled by the Goods Issue flow (PHASE 5, App\Services\GoodsIssueService).
 */
class RequestService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly StockLedgerService $ledger,
    ) {}

    /** @param array{purpose:string, notes?:?string, requester_name?:?string, requester_wa?:?string, request_date?:?string, work_location?:?string, department_id?:?int, needed_date?:?string, request_ip?:?string, items:array<int,array{item_id?:?int, description_raw:string, qty_requested:float, unit_id?:?int}>} $data */
    public function create(User $user, array $data): MaterialRequest
    {
        return DB::transaction(function () use ($user, $data) {
            $site = $user->site;
            $prefix = $site ? Str::of($site->code)->afterLast('-')->value() : 'REQ';

            $ip = $data['request_ip'] ?? null;

            $request = MaterialRequest::create([
                'number' => $this->numbers->next('REQ', $prefix),
                'requester_id' => $user->id,
                'requester_name' => $data['requester_name'] ?? $user->name,
                'requester_wa' => $data['requester_wa'] ?? $user->phone,
                'department_id' => $data['department_id'] ?? $user->employee?->department_id,
                'site_id' => $site?->id ?? $data['site_id'],
                'purpose' => $data['purpose'],
                'notes' => $data['notes'] ?? null,
                'request_date' => $data['request_date'] ?? now()->toDateString(),
                'work_location' => $data['work_location'] ?? null,
                'needed_date' => $data['needed_date'] ?? null,
                'request_ip' => $ip,
                'network_label' => IpClassifier::classify($ip),
                'status' => 'DRAFT',
                'created_by' => $user->id,
            ]);

            $this->syncItems($request, $data['items']);

            return $request->load('items');
        });
    }

    /** @param array<int,array> $items */
    public function update(MaterialRequest $request, array $data): MaterialRequest
    {
        $this->assertStatus($request, ['DRAFT']);

        return DB::transaction(function () use ($request, $data) {
            $request->fill(array_filter([
                'purpose' => $data['purpose'] ?? null,
                'notes' => $data['notes'] ?? null,
                'requester_name' => $data['requester_name'] ?? null,
                'requester_wa' => $data['requester_wa'] ?? null,
                'request_date' => $data['request_date'] ?? null,
                'work_location' => $data['work_location'] ?? null,
                'department_id' => $data['department_id'] ?? null,
                'needed_date' => $data['needed_date'] ?? null,
            ], fn ($v) => $v !== null))->save();

            if (isset($data['items'])) {
                $request->items()->delete();
                $this->syncItems($request, $data['items']);
            }

            return $request->load('items');
        });
    }

    public function submit(MaterialRequest $request): MaterialRequest
    {
        $this->assertStatus($request, ['DRAFT']);

        if ($request->items()->count() === 0) {
            throw ValidationException::withMessages(['items' => ['Request harus punya minimal 1 barang.']]);
        }

        return DB::transaction(function () use ($request) {
            foreach ($request->items()->with('item.effectiveSafetyStock')->get() as $line) {
                $this->snapshotStock($line);
            }
            $request->update(['status' => 'SUBMITTED', 'submitted_at' => now()]);

            return $request->fresh('items');
        });
    }

    public function review(MaterialRequest $request, User $reviewer): MaterialRequest
    {
        $this->assertStatus($request, ['SUBMITTED', 'UNDER_REVIEW']);

        $request->update([
            'status' => 'UNDER_REVIEW',
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
        ]);

        // refresh snapshots at review time
        foreach ($request->items()->with('item.effectiveSafetyStock')->get() as $line) {
            $this->snapshotStock($line);
        }

        return $request->fresh('items');
    }

    public function physicalCheck(
        MaterialRequestItem $line,
        User $user,
        string $status,
        ?float $qty,
        ?string $note,
    ): MaterialRequestItem {
        $this->assertStatus($line->request, ['UNDER_REVIEW']);

        if ($status === 'VERIFIED_MISMATCH' && ! filled($note)) {
            throw ValidationException::withMessages([
                'note' => ['Catatan wajib bila fisik tidak sesuai sistem.'],
            ]);
        }

        $line->update([
            'physical_check_status' => $status,
            'physical_check_qty' => $qty,
            'physical_check_note' => $note,
            'physical_checked_by' => $user->id,
        ]);

        return $line;
    }

    public function reserve(MaterialRequest $request, User $user): MaterialRequest
    {
        $this->assertStatus($request, ['UNDER_REVIEW', 'PARTIAL', 'NEED_PURCHASE']);

        return DB::transaction(function () use ($request, $user) {
            $batch = (string) Str::uuid();
            $anyReserved = false;
            $anyShort = false;

            foreach ($request->items()->with('item')->get() as $line) {
                if ($line->item_id === null) {
                    $line->update(['line_status' => 'NEED_PURCHASE', 'qty_to_purchase' => $line->qty_requested]);
                    $anyShort = true;

                    continue;
                }

                if ($line->physical_check_status === 'VERIFIED_MISMATCH') {
                    // blocked until stock opname resolves the discrepancy
                    $line->update(['line_status' => 'PENDING']);
                    $anyShort = true;

                    continue;
                }

                if ($line->line_status === 'RESERVED') {
                    $anyReserved = true;

                    continue;
                }

                $warehouseId = $line->warehouse_id ?? $line->item->default_warehouse_id;
                if ($warehouseId === null) {
                    $line->update(['line_status' => 'NEED_PURCHASE', 'qty_to_purchase' => $line->qty_requested]);
                    $anyShort = true;

                    continue;
                }

                $approved = $line->qty_approved ?? $line->qty_requested;
                $inv = Inventory::firstOrCreate(
                    ['item_id' => $line->item_id, 'warehouse_id' => $warehouseId],
                    ['actual_qty' => 0, 'reserved_qty' => 0]
                );
                $available = (float) $inv->available_qty;

                $toReserve = max(min($approved, $available), 0.0);
                $toPurchase = round($approved - $toReserve, 2);

                if ($toReserve > 0) {
                    $this->ledger->record([
                        'type' => 'RESERVATION',
                        'item_id' => $line->item_id,
                        'warehouse_id' => $warehouseId,
                        'reserve_delta' => $toReserve,
                        'reference' => $line,
                        'batch_uuid' => $batch,
                        'created_by' => $user->id,
                        'note' => "Reserve untuk {$request->number}",
                    ]);

                    StockReservation::create([
                        'item_id' => $line->item_id,
                        'warehouse_id' => $warehouseId,
                        'material_request_id' => $request->id,
                        'material_request_item_id' => $line->id,
                        'qty' => $toReserve,
                        'status' => 'ACTIVE',
                        'reserved_by' => $user->id,
                        'reserved_at' => now(),
                        'expires_at' => now()->addDays((int) config('stockwise.engine.reservation_expiry_days', 14)),
                    ]);
                    $anyReserved = true;
                }

                if ($toPurchase > 0) {
                    $anyShort = true;
                }

                $line->update([
                    'warehouse_id' => $warehouseId,
                    'qty_approved' => $approved,
                    'qty_reserved' => $toReserve,
                    'qty_to_purchase' => $toPurchase,
                    'line_status' => match (true) {
                        $toReserve <= 0 => 'NEED_PURCHASE',
                        $toPurchase > 0 => 'PARTIAL',
                        default => 'RESERVED',
                    },
                ]);
            }

            $status = match (true) {
                $anyReserved && $anyShort => 'PARTIAL',
                $anyShort => 'NEED_PURCHASE',
                $anyReserved => 'RESERVED',
                default => 'READY',
            };
            $request->update(['status' => $status]);

            return $request->fresh('items');
        });
    }

    public function cancel(MaterialRequest $request, User $user, string $reason): MaterialRequest
    {
        if (in_array($request->status, ['PICKED_UP', 'COMPLETED', 'CANCELLED'], true)) {
            throw ValidationException::withMessages(['status' => ['Request tidak bisa dibatalkan pada status ini.']]);
        }

        return DB::transaction(function () use ($request, $user, $reason) {
            $batch = (string) Str::uuid();

            foreach ($request->reservations()->where('status', 'ACTIVE')->get() as $res) {
                $this->ledger->record([
                    'type' => 'RELEASE_RESERVATION',
                    'item_id' => $res->item_id,
                    'warehouse_id' => $res->warehouse_id,
                    'reserve_delta' => -$res->qty,
                    'reference' => $res,
                    'batch_uuid' => $batch,
                    'created_by' => $user->id,
                    'note' => "Batal {$request->number}",
                ]);
                $res->update(['status' => 'RELEASED', 'released_at' => now()]);
            }

            $request->items()->update(['line_status' => 'CANCELLED', 'qty_reserved' => 0]);
            $request->update(['status' => 'CANCELLED', 'cancel_reason' => $reason]);

            return $request->fresh('items');
        });
    }

    // ---------------------------------------------------------------------

    /** @param array<int,array> $items */
    private function syncItems(MaterialRequest $request, array $items): void
    {
        foreach ($items as $row) {
            $item = ! empty($row['item_id']) ? Item::find($row['item_id']) : null;

            $request->items()->create([
                'item_id' => $item?->id,
                'description_raw' => $row['description_raw'] ?? $item?->description ?? '-',
                'qty_requested' => $row['qty_requested'],
                'unit_id' => $row['unit_id'] ?? $item?->unit_id,
                'warehouse_id' => $row['warehouse_id'] ?? $item?->default_warehouse_id,
                'line_status' => 'PENDING',
            ]);
        }
    }

    private function snapshotStock(MaterialRequestItem $line): void
    {
        if ($line->item_id === null) {
            $line->update([
                'system_stock_snapshot' => null,
                'projected_stock' => null,
                'below_safety_flag' => false,
                'line_status' => 'NEED_PURCHASE',
            ]);

            return;
        }

        $warehouseId = $line->warehouse_id ?? $line->item->default_warehouse_id;
        $query = Inventory::where('item_id', $line->item_id);
        if ($warehouseId) {
            $query->where('warehouse_id', $warehouseId);
        }
        $available = (float) ($query->sum('actual_qty') - $query->sum('reserved_qty'));
        $safety = (float) ($line->item->effectiveSafetyStock?->safety_stock ?? 0);
        $projected = $available - (float) $line->qty_requested;

        $line->update([
            'warehouse_id' => $warehouseId,
            'system_stock_snapshot' => $available,
            'safety_stock_snapshot' => $safety,
            'projected_stock' => $projected,
            'below_safety_flag' => $projected < $safety,
        ]);
    }

    /** @param list<string> $allowed */
    private function assertStatus(MaterialRequest $request, array $allowed): void
    {
        if (! in_array($request->status, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => ["Aksi tidak valid untuk status {$request->status}."],
            ]);
        }
    }
}
