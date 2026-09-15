<?php

namespace App\Services;

use App\Models\GoodsIssue;
use App\Models\Item;
use App\Models\MaterialRequest;
use App\Models\StockReservation;
use App\Models\User;
use App\Services\Inventory\StockLedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Goods issued against a warehouse reservation — docs/status-flow.md §2, business-process.md BP-2.
 * DRAFT -> PREPARING -> READY_TO_PICKUP -> PICKED_UP -> COMPLETED.
 * `actual_qty` decreases ONLY on pickup (ATURAN MUTLAK 7).
 * Formerly `NpbgService` — renamed alongside the `npbg` table so that name can represent the
 * real NPBG (Accurate ARINV/ARINVDET mirror, see NpbgService/NpbgController).
 */
class GoodsIssueService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly StockLedgerService $ledger,
    ) {}

    /** Build a PREPARING goods issue from a request's reserved lines. */
    public function createFromRequest(MaterialRequest $request, User $user): GoodsIssue
    {
        if (! in_array($request->status, ['RESERVED', 'PARTIAL'], true)) {
            throw ValidationException::withMessages([
                'status' => ['Bukti keluar barang hanya bisa dibuat dari request RESERVED/PARTIAL.'],
            ]);
        }

        $lines = $request->items()
            ->where('line_status', 'RESERVED')
            ->where('qty_reserved', '>', 0)
            ->with('item.unit')
            ->get();

        if ($lines->isEmpty()) {
            throw ValidationException::withMessages(['items' => ['Tidak ada baris ter-reserve.']]);
        }

        return DB::transaction(function () use ($request, $user, $lines) {
            $goodsIssue = $this->makeHeader([
                'classification' => 'UMUM',
                'material_request_id' => $request->id,
                'requester_id' => $request->requester_id,
                'department_id' => $request->department_id,
                'site_id' => $request->site_id,
                'warehouse_id' => $lines->first()->warehouse_id,
                'created_by' => $user->id,
            ]);

            $no = 1;
            foreach ($lines as $line) {
                $reservation = StockReservation::where('material_request_item_id', $line->id)
                    ->where('status', 'ACTIVE')->first();

                $goodsIssue->items()->create([
                    'item_id' => $line->item_id,
                    'material_request_item_id' => $line->id,
                    'stock_reservation_id' => $reservation?->id,
                    'description_raw' => $line->description_raw,
                    'item_no' => $no++,
                    'qty' => $line->qty_reserved,
                    'unit_id' => $line->unit_id,
                    'warehouse_id' => $line->warehouse_id,
                ]);
            }

            $request->update([
                'status' => 'PREPARING',
                'npbg_no' => $request->npbg_no ?: $goodsIssue->number,
            ]);

            return $goodsIssue->load('items');
        });
    }

    /**
     * Manual goods issue (e.g. daily UMUM consumption, no request).
     *
     * @param  array{classification?:string, type?:string, warehouse_id:int, site_id:int, requester_name?:?string, notes?:?string, items:array<int,array{item_id:int, qty:float, unit_id?:?int, note?:?string}>}  $data
     */
    public function createManual(User $user, array $data): GoodsIssue
    {
        return DB::transaction(function () use ($user, $data) {
            $goodsIssue = $this->makeHeader([
                'classification' => $data['classification'] ?? 'UMUM',
                'type' => $data['type'] ?? 'NON_PENJUALAN',
                'warehouse_id' => $data['warehouse_id'],
                'site_id' => $data['site_id'] ?? $user->site_id,
                'requester_id' => $user->id,
                'requester_name' => $data['requester_name'] ?? $user->name,
                'department_id' => $user->employee?->department_id,
                'customer_name' => $data['customer_name'] ?? null,
                'project_name' => $data['project_name'] ?? null,
                'asset_ref' => $data['asset_ref'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
            ]);

            $no = 1;
            foreach ($data['items'] as $row) {
                $item = Item::find($row['item_id']);
                $goodsIssue->items()->create([
                    'item_id' => $item?->id,
                    'description_raw' => $row['description_raw'] ?? $item?->description ?? '-',
                    'item_no' => $no++,
                    'qty' => $row['qty'],
                    'unit_id' => $row['unit_id'] ?? $item?->unit_id,
                    'warehouse_id' => $data['warehouse_id'],
                    'note' => $row['note'] ?? null,
                ]);
            }

            return $goodsIssue->load('items');
        });
    }

    /**
     * Ubah goods issue selagi belum siap diambil — belum ada stok yang bergerak (ATURAN MUTLAK 7).
     * Header (klasifikasi, pelanggan/proyek/aset, catatan) selalu bisa diubah; baris item hanya
     * boleh diganti untuk goods issue manual (bukan hasil request — qty di sana terikat reservasi).
     *
     * @param  array{classification?:string, customer_name?:?string, project_name?:?string, asset_ref?:?string, requester_name?:?string, notes?:?string, items?:array<int,array{item_id?:?int, description_raw?:string, qty:float, unit_id?:?int, note?:?string}>}  $data
     */
    public function update(GoodsIssue $goodsIssue, array $data): GoodsIssue
    {
        $this->assert($goodsIssue, ['DRAFT', 'PREPARING']);

        return DB::transaction(function () use ($goodsIssue, $data) {
            $goodsIssue->fill(array_filter([
                'classification' => $data['classification'] ?? null,
                'customer_name' => $data['customer_name'] ?? null,
                'project_name' => $data['project_name'] ?? null,
                'asset_ref' => $data['asset_ref'] ?? null,
                'requester_name' => $data['requester_name'] ?? null,
                'notes' => $data['notes'] ?? null,
            ], fn ($v) => $v !== null))->save();

            if (isset($data['items'])) {
                if ($goodsIssue->material_request_id !== null) {
                    throw ValidationException::withMessages([
                        'items' => ['Baris dari request mengikuti reservasi — hanya bisa dibatalkan, bukan diubah qty-nya.'],
                    ]);
                }

                $warehouseId = $goodsIssue->items()->value('warehouse_id') ?? $goodsIssue->warehouse_id;
                $goodsIssue->items()->delete();
                $no = 1;
                foreach ($data['items'] as $row) {
                    $item = ! empty($row['item_id']) ? Item::find($row['item_id']) : null;
                    $goodsIssue->items()->create([
                        'item_id' => $item?->id,
                        'description_raw' => $row['description_raw'] ?? $item?->description ?? '-',
                        'item_no' => $no++,
                        'qty' => $row['qty'],
                        'unit_id' => $row['unit_id'] ?? $item?->unit_id,
                        'warehouse_id' => $warehouseId,
                        'note' => $row['note'] ?? null,
                    ]);
                }
            }

            return $goodsIssue->fresh('items');
        });
    }

    public function prepare(GoodsIssue $goodsIssue): GoodsIssue
    {
        $this->assert($goodsIssue, ['DRAFT', 'PREPARING']);
        $goodsIssue->update(['status' => 'PREPARING']);

        return $goodsIssue;
    }

    public function ready(GoodsIssue $goodsIssue): GoodsIssue
    {
        $this->assert($goodsIssue, ['PREPARING']);
        $goodsIssue->update(['status' => 'READY_TO_PICKUP']);

        return $goodsIssue;
    }

    public function pickup(GoodsIssue $goodsIssue, User $user, string $pickedUpBy, ?string $signature): GoodsIssue
    {
        $this->assert($goodsIssue, ['READY_TO_PICKUP', 'PREPARING']);

        return DB::transaction(function () use ($goodsIssue, $user, $pickedUpBy, $signature) {
            $batch = (string) Str::uuid();

            foreach ($goodsIssue->items()->with('reservation')->get() as $line) {
                if ($line->item_id === null || $line->qty <= 0) {
                    continue;
                }

                $reserveDelta = 0.0;
                if ($line->reservation && $line->reservation->status === 'ACTIVE') {
                    $reserveDelta = -min((float) $line->reservation->qty, (float) $line->qty);
                }

                $this->ledger->record([
                    'type' => 'STOCK_OUT',
                    'item_id' => $line->item_id,
                    'warehouse_id' => $line->warehouse_id,
                    'qty' => $line->qty,
                    'reserve_delta' => $reserveDelta,
                    'reference' => $line,
                    'batch_uuid' => $batch,
                    'created_by' => $user->id,
                    'note' => "Pickup {$goodsIssue->number}",
                ]);

                $line->update(['qty_issued' => $line->qty]);

                if ($line->reservation && $line->reservation->status === 'ACTIVE') {
                    $line->reservation->update(['status' => 'CONSUMED']);
                }
                if ($line->material_request_item_id && $line->requestItem) {
                    $line->requestItem->increment('qty_issued', (float) $line->qty);
                    $line->requestItem->update(['line_status' => 'ISSUED']);
                }
            }

            $path = $this->storeSignature($goodsIssue, $signature);

            $goodsIssue->update([
                'status' => 'PICKED_UP',
                'picked_up_by' => $pickedUpBy,
                'picked_up_at' => now(),
                'issued_by' => $user->id,
                'signature_path' => $path,
            ]);

            $goodsIssue->update(['status' => 'COMPLETED']);
            $this->syncRequestCompletion($goodsIssue);

            return $goodsIssue->fresh('items');
        });
    }

    public function cancel(GoodsIssue $goodsIssue, User $user, string $reason): GoodsIssue
    {
        if (in_array($goodsIssue->status, ['PICKED_UP', 'COMPLETED', 'CANCELLED'], true)) {
            throw ValidationException::withMessages(['status' => ['Tidak bisa dibatalkan.']]);
        }
        $goodsIssue->update(['status' => 'CANCELLED', 'cancel_reason' => $reason]);

        if ($goodsIssue->request && $goodsIssue->request->status === 'PREPARING') {
            $goodsIssue->request->update(['status' => 'RESERVED']);
        }

        return $goodsIssue;
    }

    // ------------------------------------------------------------------

    private function makeHeader(array $attrs): GoodsIssue
    {
        $date = now();
        $prefix = $attrs['prefix'] ?? 'NA';
        $number = $this->numbers->next('BKB', $prefix, $date);
        [, , $yy, $rom, $seq] = explode('/', $number);

        return GoodsIssue::create(array_merge([
            'number' => $number,
            'prefix' => $prefix,
            'year' => (int) $yy,
            'month' => (int) $date->format('n'),
            'sequence' => (int) $seq,
            'date' => $date->toDateString(),
            'type' => 'NON_PENJUALAN',
            'status' => 'PREPARING',
        ], $attrs));
    }

    private function storeSignature(GoodsIssue $goodsIssue, ?string $signature): ?string
    {
        if (! $signature) {
            return null;
        }
        if (Str::startsWith($signature, 'data:image')) {
            [$meta, $b64] = explode(',', $signature, 2);
            $ext = Str::contains($meta, 'png') ? 'png' : 'jpg';
            $path = "signatures/{$goodsIssue->number}.{$ext}";
            Storage::disk('local')->put($path, base64_decode($b64));

            return $path;
        }

        return $signature; // treat as an external ref
    }

    private function syncRequestCompletion(GoodsIssue $goodsIssue): void
    {
        $request = $goodsIssue->request;
        if (! $request) {
            return;
        }

        $pending = $request->items()
            ->whereNotIn('line_status', ['ISSUED', 'CANCELLED'])
            ->exists();

        $request->update([
            'status' => $pending ? 'PARTIAL' : 'COMPLETED',
            'completed_at' => $pending ? null : now(),
        ]);
    }

    /** @param list<string> $allowed */
    private function assert(GoodsIssue $goodsIssue, array $allowed): void
    {
        if (! in_array($goodsIssue->status, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => ["Aksi tidak valid untuk status {$goodsIssue->status}."],
            ]);
        }
    }
}
