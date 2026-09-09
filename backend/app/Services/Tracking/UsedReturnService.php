<?php

namespace App\Services\Tracking;

use App\Models\UsedReturn;
use App\Models\User;
use App\Services\DocumentNumberService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pengembalian Bekas — sisa/bekas/rusak dari pemakaian dikembalikan. docs/status-flow.md §8.
 * PENDING -> CLEAR (bekas dicatat lewat RI CONFIRMED).
 * item into_stock=true & condition REUSABLE/USED -> movement RETURN saat RI CONFIRMED (di ReceivingService).
 * qty negatif (shortage) -> hanya catatan, tidak gerakkan stok.
 */
class UsedReturnService
{
    public function __construct(private readonly DocumentNumberService $numbers) {}

    /**
     * @param  array{npbg_id?:?int, npbg_ref_raw?:?string, return_date?:?string, format?:string, site_id?:?int, note?:?string, items:array<int,array{item_id?:?int, component_type_id?:?int, description_raw?:string, qty:float, unit_id?:?int, condition?:string, into_stock?:bool}>}  $data
     */
    public function create(User $user, array $data): UsedReturn
    {
        if (empty($data['items'])) {
            throw ValidationException::withMessages(['items' => ['Minimal 1 baris.']]);
        }
        $date = Carbon::parse($data['return_date'] ?? now());

        return DB::transaction(function () use ($user, $data, $date) {
            $ur = UsedReturn::create([
                'number' => $this->numbers->next('UR', 'SDA', $date),
                'npbg_id' => $data['npbg_id'] ?? null,
                'npbg_ref_raw' => $data['npbg_ref_raw'] ?? null,
                'return_date' => $date->toDateString(),
                'status' => 'PENDING',
                'format' => $data['format'] ?? 'ITEM_LINE',
                'site_id' => $data['site_id'] ?? $user->site_id,
                'note' => $data['note'] ?? null,
                'created_by' => $user->id,
            ]);

            foreach (array_values($data['items']) as $i => $row) {
                $ur->items()->create([
                    'item_id' => $row['item_id'] ?? null,
                    'component_type_id' => $row['component_type_id'] ?? null,
                    'description_raw' => $row['description_raw'] ?? null,
                    'qty' => $row['qty'],
                    'unit_id' => $row['unit_id'] ?? null,
                    'condition' => strtoupper($row['condition'] ?? 'USED'),
                    'into_stock' => $row['into_stock'] ?? false,
                    'item_no' => $i + 1,
                ]);
            }

            return $ur->load('items');
        });
    }

    /**
     * @param  array{ri_id?:?int, return_date?:?string}  $data
     */
    public function close(UsedReturn $ur, array $data): UsedReturn
    {
        if ($ur->status === 'CLEAR') {
            throw ValidationException::withMessages(['status' => ['Pengembalian bekas sudah CLEAR.']]);
        }
        $ur->update([
            'status' => 'CLEAR',
            'ri_id' => $data['ri_id'] ?? $ur->ri_id,
            'return_date' => $data['return_date'] ?? $ur->return_date?->toDateString() ?? now()->toDateString(),
        ]);

        return $ur->refresh()->load('items');
    }
}
