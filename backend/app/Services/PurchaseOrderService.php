<?php

namespace App\Services;

use App\Models\Item;
use App\Models\PurchaseOrder;
use App\Models\PurchaseProposal;
use App\Models\PurchaseProposalItem;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Purchase Order — docs/status-flow.md §5.
 * DRAFT -> APPROVED -> SENT -> PARTIAL_RECEIVED -> RECEIVED -> CLOSED.
 */
class PurchaseOrderService
{
    public function __construct(private readonly DocumentNumberService $numbers) {}

    /**
     * @param  array{vendor_id:int, ppb_id?:?int, expected_date?:?string, tax_percent?:float, lines:array<int,array{ppb_item_id?:?int, item_id?:?int, description_raw?:string, qty:float, unit_id?:?int, unit_price:float}>}  $data
     */
    public function create(User $user, array $data): PurchaseOrder
    {
        $vendor = Vendor::findOrFail($data['vendor_id']);
        $ppb = ! empty($data['ppb_id']) ? PurchaseProposal::findOrFail($data['ppb_id']) : null;

        return DB::transaction(function () use ($user, $data, $vendor, $ppb) {
            $date = now();
            $number = $this->numbers->next('PO', 'BL', $date);
            [, , $yy, , $seq] = explode('/', $number);

            $po = PurchaseOrder::create([
                'number' => $number,
                'prefix' => 'BL',
                'year' => (int) $yy,
                'month' => (int) $date->format('n'),
                'sequence' => (int) $seq,
                'date' => $date->toDateString(),
                'vendor_id' => $vendor->id,
                'ppb_id' => $ppb?->id,
                'site_id' => $ppb?->site_id ?? $user->site_id,
                'expected_date' => $data['expected_date'] ?? null,
                'status' => 'DRAFT',
                'created_by' => $user->id,
            ]);

            $subtotal = 0;
            foreach ($data['lines'] as $row) {
                $item = ! empty($row['item_id']) ? Item::find($row['item_id']) : null;
                $lineTotal = round($row['qty'] * $row['unit_price'], 2);
                $subtotal += $lineTotal;

                $po->items()->create([
                    'ppb_item_id' => $row['ppb_item_id'] ?? null,
                    'item_id' => $item?->id,
                    'description_raw' => $row['description_raw'] ?? $item?->description ?? '-',
                    'qty' => $row['qty'],
                    'unit_id' => $row['unit_id'] ?? $item?->unit_id,
                    'unit_price' => $row['unit_price'],
                    'line_total' => $lineTotal,
                    'line_status' => 'PENDING',
                ]);

                if (! empty($row['ppb_item_id'])) {
                    PurchaseProposalItem::where('id', $row['ppb_item_id'])
                        ->update(['line_status' => 'ORDERED']);
                    PurchaseProposalItem::where('id', $row['ppb_item_id'])
                        ->increment('qty_ordered', (float) $row['qty']);
                }
            }

            $tax = round($subtotal * (($data['tax_percent'] ?? 0) / 100), 2);
            $po->update(['subtotal' => $subtotal, 'tax' => $tax, 'total' => $subtotal + $tax]);

            $ppb?->update(['status' => 'ORDERED']);

            return $po->load('items');
        });
    }

    public function approve(PurchaseOrder $po, User $user): PurchaseOrder
    {
        $this->assert($po, ['DRAFT']);
        $po->update(['status' => 'APPROVED', 'approved_by' => $user->id, 'approved_at' => now()]);

        return $po;
    }

    public function send(PurchaseOrder $po): PurchaseOrder
    {
        $this->assert($po, ['APPROVED']);
        $po->update(['status' => 'SENT']);

        return $po;
    }

    public function cancel(PurchaseOrder $po, string $reason): PurchaseOrder
    {
        abort_if(in_array($po->status, ['RECEIVED', 'CLOSED', 'CANCELLED'], true), 422, 'PO tidak bisa dibatalkan.');
        abort_if($po->receivings()->where('status', 'CONFIRMED')->exists(), 422, 'PO sudah ada penerimaan.');
        $po->update(['status' => 'CANCELLED', 'notes' => trim(($po->notes ?? '')."\nDibatalkan: {$reason}")]);

        return $po;
    }

    /** @param list<string> $allowed */
    private function assert(PurchaseOrder $po, array $allowed): void
    {
        if (! in_array($po->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => ["Aksi tidak valid untuk status PO {$po->status}."]]);
        }
    }
}
