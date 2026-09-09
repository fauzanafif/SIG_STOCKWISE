<?php

namespace App\Services\Tracking;

use App\Models\MaintenanceOrder;
use App\Models\MaintenanceOrderSub;
use App\Models\User;
use App\Services\DocumentNumberService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Maintenance Asset (SPK + Sub SPK). docs/status-flow.md §8.
 * order: OPEN -> ON_GOING (ada sub) -> COMPLETED (semua sub COMPLETED).
 * sub:   ON_GOING -> COMPLETED (finish_date + result_note).
 */
class MaintenanceService
{
    public function __construct(private readonly DocumentNumberService $numbers) {}

    /**
     * @param  array{asset_id:int, site_id:int, report_date?:?string, reported_by?:?int, problem_summary?:?string}  $data
     */
    public function createOrder(User $user, array $data): MaintenanceOrder
    {
        $date = Carbon::parse($data['report_date'] ?? now());

        return MaintenanceOrder::create([
            'number' => $this->numbers->next('SPK', 'SDA', $date),
            'report_date' => $date->toDateString(),
            'asset_id' => $data['asset_id'],
            'site_id' => $data['site_id'],
            'reported_by' => $data['reported_by'] ?? null,
            'problem_summary' => $data['problem_summary'] ?? null,
            'status' => 'OPEN',
            'created_by' => $user->id,
        ]);
    }

    /**
     * @param  array{workshop_id?:?int, workshop_raw?:?string, problem_detail?:?string, npbg_id?:?int}  $data
     */
    public function addSub(MaintenanceOrder $order, array $data): MaintenanceOrderSub
    {
        $this->assertOrder($order, ['OPEN', 'ON_GOING']);
        $n = $order->subs()->count() + 1;

        return DB::transaction(function () use ($order, $data, $n) {
            $sub = $order->subs()->create([
                'sub_no' => 'SUB-'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
                'workshop_id' => $data['workshop_id'] ?? null,
                'workshop_raw' => $data['workshop_raw'] ?? null,
                'problem_detail' => $data['problem_detail'] ?? null,
                'npbg_id' => $data['npbg_id'] ?? null,
                'status' => 'ON_GOING',
            ]);
            if ($order->status === 'OPEN') {
                $order->update(['status' => 'ON_GOING']);
            }

            return $sub;
        });
    }

    /**
     * @param  array{finish_date?:?string, result_note:string, ri_id?:?int}  $data
     */
    public function completeSub(MaintenanceOrderSub $sub, array $data): MaintenanceOrderSub
    {
        if ($sub->status === 'COMPLETED') {
            throw ValidationException::withMessages(['status' => ['Sub SPK sudah COMPLETED.']]);
        }
        if (trim((string) ($data['result_note'] ?? '')) === '') {
            throw ValidationException::withMessages(['result_note' => ['Catatan hasil wajib diisi.']]);
        }

        return DB::transaction(function () use ($sub, $data) {
            $sub->update([
                'status' => 'COMPLETED',
                'finish_date' => $data['finish_date'] ?? now()->toDateString(),
                'result_note' => $data['result_note'],
                'ri_id' => $data['ri_id'] ?? $sub->ri_id,
            ]);
            $this->rollUp($sub->order);

            return $sub->refresh();
        });
    }

    private function rollUp(MaintenanceOrder $order): void
    {
        $order->load('subs');
        if ($order->subs->isNotEmpty() && $order->subs->every(fn ($s) => $s->status === 'COMPLETED')) {
            $order->update(['status' => 'COMPLETED', 'completed_at' => now()->toDateString()]);
        }
    }

    /** @param list<string> $allowed */
    private function assertOrder(MaintenanceOrder $order, array $allowed): void
    {
        if (! in_array($order->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => ["Aksi tidak valid untuk status SPK {$order->status}."]]);
        }
    }
}
