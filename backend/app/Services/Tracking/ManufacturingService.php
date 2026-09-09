<?php

namespace App\Services\Tracking;

use App\Models\ManufacturingOrder;
use App\Models\ManufacturingOrderSub;
use App\Models\User;
use App\Services\DocumentNumberService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Manufaktur & Assembly / Jasa (MO + Sub). docs/status-flow.md §8.
 * order: REQUESTED -> ON_GOING -> COMPLETED (semua sub COMPLETED).
 * kind=JASA butuh vendor_id. Produk jadi masuk stok lewat RI (source MANUFACTURING_OUTPUT).
 */
class ManufacturingService
{
    public function __construct(private readonly DocumentNumberService $numbers) {}

    /**
     * @param  array{kind?:string, date?:?string, product_name?:?string, site_id:int, vendor_id?:?int}  $data
     */
    public function createOrder(User $user, array $data): ManufacturingOrder
    {
        $kind = strtoupper($data['kind'] ?? 'ASSEMBLY');
        if ($kind === 'JASA' && empty($data['vendor_id'])) {
            throw ValidationException::withMessages(['vendor_id' => ['Vendor wajib untuk order JASA.']]);
        }
        $date = Carbon::parse($data['date'] ?? now());

        return ManufacturingOrder::create([
            'number' => $this->numbers->next($kind === 'JASA' ? 'MJ' : 'MA', 'SDA', $date),
            'kind' => $kind,
            'date' => $date->toDateString(),
            'product_name' => $data['product_name'] ?? null,
            'site_id' => $data['site_id'],
            'vendor_id' => $data['vendor_id'] ?? null,
            'status' => 'REQUESTED',
            'created_by' => $user->id,
        ]);
    }

    /**
     * @param  array{process?:?string, serial_no_raw?:?string, npbg_id?:?int, note_start?:?string}  $data
     */
    public function addSub(ManufacturingOrder $order, array $data): ManufacturingOrderSub
    {
        $this->assertOrder($order, ['REQUESTED', 'ON_GOING']);
        $n = $order->subs()->count() + 1;

        return DB::transaction(function () use ($order, $data, $n) {
            $sub = $order->subs()->create([
                'sub_no' => 'SUB-'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
                'item_no' => $n,
                'process' => $data['process'] ?? null,
                'serial_no_raw' => $data['serial_no_raw'] ?? null,
                'npbg_id' => $data['npbg_id'] ?? null,
                'note_start' => $data['note_start'] ?? null,
                'status' => 'ON_GOING',
            ]);
            if ($order->status === 'REQUESTED') {
                $order->update(['status' => 'ON_GOING']);
            }

            return $sub;
        });
    }

    /**
     * @param  array{finish_date?:?string, note_end?:?string, ri_id?:?int}  $data
     */
    public function completeSub(ManufacturingOrderSub $sub, array $data): ManufacturingOrderSub
    {
        if ($sub->status === 'COMPLETED') {
            throw ValidationException::withMessages(['status' => ['Sub sudah COMPLETED.']]);
        }

        return DB::transaction(function () use ($sub, $data) {
            $sub->update([
                'status' => 'COMPLETED',
                'finish_date' => $data['finish_date'] ?? now()->toDateString(),
                'note_end' => $data['note_end'] ?? $sub->note_end,
                'ri_id' => $data['ri_id'] ?? $sub->ri_id,
            ]);
            $order = $sub->order()->with('subs')->first();
            if ($order->subs->isNotEmpty() && $order->subs->every(fn ($s) => $s->status === 'COMPLETED')) {
                $order->update(['status' => 'COMPLETED', 'completed_at' => now()->toDateString()]);
            }

            return $sub->refresh();
        });
    }

    /** @param list<string> $allowed */
    private function assertOrder(ManufacturingOrder $order, array $allowed): void
    {
        if (! in_array($order->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => ["Aksi tidak valid untuk status MO {$order->status}."]]);
        }
    }
}
