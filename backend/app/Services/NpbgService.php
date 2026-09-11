<?php

namespace App\Services;

use App\Models\Item;
use App\Models\MaterialRequest;
use App\Models\Npbg;
use App\Models\StockReservation;
use App\Models\User;
use App\Services\Inventory\StockLedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * NPBG (goods issue) — docs/status-flow.md §2, business-process.md BP-2.
 * DRAFT -> PREPARING -> READY_TO_PICKUP -> PICKED_UP -> COMPLETED.
 * `actual_qty` decreases ONLY on pickup (ATURAN MUTLAK 7).
 */
class NpbgService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly StockLedgerService $ledger,
    ) {}

    /** Build a PREPARING NPBG from a request's reserved lines. */
    public function createFromRequest(MaterialRequest $request, User $user): Npbg
    {
        if (! in_array($request->status, ['RESERVED', 'PARTIAL'], true)) {
            throw ValidationException::withMessages([
                'status' => ['NPBG hanya bisa dibuat dari request RESERVED/PARTIAL.'],
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
            $npbg = $this->makeHeader([
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

                $npbg->items()->create([
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
                'npbg_no' => $request->npbg_no ?: $npbg->number,
            ]);

            return $npbg->load('items');
        });
    }

    /**
     * Manual NPBG (e.g. daily UMUM consumption, no request).
     *
     * @param  array{classification?:string, type?:string, warehouse_id:int, site_id:int, requester_name?:?string, notes?:?string, items:array<int,array{item_id:int, qty:float, unit_id?:?int, note?:?string}>}  $data
     */
    public function createManual(User $user, array $data): Npbg
    {
        return DB::transaction(function () use ($user, $data) {
            $npbg = $this->makeHeader([
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
                $npbg->items()->create([
                    'item_id' => $item?->id,
                    'description_raw' => $row['description_raw'] ?? $item?->description ?? '-',
                    'item_no' => $no++,
                    'qty' => $row['qty'],
                    'unit_id' => $row['unit_id'] ?? $item?->unit_id,
                    'warehouse_id' => $data['warehouse_id'],
                    'note' => $row['note'] ?? null,
                ]);
            }

            return $npbg->load('items');
        });
    }

    /**
     * Ubah NPBG selagi belum siap diambil — belum ada stok yang bergerak (ATURAN MUTLAK 7).
     * Header (klasifikasi, pelanggan/proyek/aset, catatan) selalu bisa diubah; baris item hanya
     * boleh diganti untuk NPBG manual (bukan hasil request — qty di sana terikat reservasi).
     *
     * @param  array{classification?:string, customer_name?:?string, project_name?:?string, asset_ref?:?string, requester_name?:?string, notes?:?string, items?:array<int,array{item_id?:?int, description_raw?:string, qty:float, unit_id?:?int, note?:?string}>}  $data
     */
    public function update(Npbg $npbg, array $data): Npbg
    {
        $this->assert($npbg, ['DRAFT', 'PREPARING']);

        return DB::transaction(function () use ($npbg, $data) {
            $npbg->fill(array_filter([
                'classification' => $data['classification'] ?? null,
                'customer_name' => $data['customer_name'] ?? null,
                'project_name' => $data['project_name'] ?? null,
                'asset_ref' => $data['asset_ref'] ?? null,
                'requester_name' => $data['requester_name'] ?? null,
                'notes' => $data['notes'] ?? null,
            ], fn ($v) => $v !== null))->save();

            if (isset($data['items'])) {
                if ($npbg->material_request_id !== null) {
                    throw ValidationException::withMessages([
                        'items' => ['Baris NPBG dari request mengikuti reservasi — hanya bisa dibatalkan, bukan diubah qty-nya.'],
                    ]);
                }

                $warehouseId = $npbg->items()->value('warehouse_id') ?? $npbg->warehouse_id;
                $npbg->items()->delete();
                $no = 1;
                foreach ($data['items'] as $row) {
                    $item = ! empty($row['item_id']) ? Item::find($row['item_id']) : null;
                    $npbg->items()->create([
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

            return $npbg->fresh('items');
        });
    }

    public function prepare(Npbg $npbg): Npbg
    {
        $this->assert($npbg, ['DRAFT', 'PREPARING']);
        $npbg->update(['status' => 'PREPARING']);

        return $npbg;
    }

    public function ready(Npbg $npbg): Npbg
    {
        $this->assert($npbg, ['PREPARING']);
        $npbg->update(['status' => 'READY_TO_PICKUP']);

        return $npbg;
    }

    public function pickup(Npbg $npbg, User $user, string $pickedUpBy, ?string $signature): Npbg
    {
        $this->assert($npbg, ['READY_TO_PICKUP', 'PREPARING']);

        return DB::transaction(function () use ($npbg, $user, $pickedUpBy, $signature) {
            $batch = (string) Str::uuid();

            foreach ($npbg->items()->with('reservation')->get() as $line) {
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
                    'note' => "Pickup {$npbg->number}",
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

            $path = $this->storeSignature($npbg, $signature);

            $npbg->update([
                'status' => 'PICKED_UP',
                'picked_up_by' => $pickedUpBy,
                'picked_up_at' => now(),
                'issued_by' => $user->id,
                'signature_path' => $path,
            ]);

            $npbg->update(['status' => 'COMPLETED']);
            $this->syncRequestCompletion($npbg);

            return $npbg->fresh('items');
        });
    }

    public function cancel(Npbg $npbg, User $user, string $reason): Npbg
    {
        if (in_array($npbg->status, ['PICKED_UP', 'COMPLETED', 'CANCELLED'], true)) {
            throw ValidationException::withMessages(['status' => ['NPBG tidak bisa dibatalkan.']]);
        }
        $npbg->update(['status' => 'CANCELLED', 'cancel_reason' => $reason]);

        if ($npbg->request && $npbg->request->status === 'PREPARING') {
            $npbg->request->update(['status' => 'RESERVED']);
        }

        return $npbg;
    }

    // ------------------------------------------------------------------

    private function makeHeader(array $attrs): Npbg
    {
        $date = now();
        $prefix = $attrs['prefix'] ?? 'NA';
        $number = $this->numbers->next('NPBG', $prefix, $date);
        [, , $yy, $rom, $seq] = explode('/', $number);

        return Npbg::create(array_merge([
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

    private function storeSignature(Npbg $npbg, ?string $signature): ?string
    {
        if (! $signature) {
            return null;
        }
        if (Str::startsWith($signature, 'data:image')) {
            [$meta, $b64] = explode(',', $signature, 2);
            $ext = Str::contains($meta, 'png') ? 'png' : 'jpg';
            $path = "signatures/{$npbg->number}.{$ext}";
            Storage::disk('local')->put($path, base64_decode($b64));

            return $path;
        }

        return $signature; // treat as an external ref
    }

    private function syncRequestCompletion(Npbg $npbg): void
    {
        $request = $npbg->request;
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
    private function assert(Npbg $npbg, array $allowed): void
    {
        if (! in_array($npbg->status, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => ["Aksi tidak valid untuk status NPBG {$npbg->status}."],
            ]);
        }
    }
}
