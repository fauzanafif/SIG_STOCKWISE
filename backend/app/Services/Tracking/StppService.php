<?php

namespace App\Services\Tracking;

use App\Models\SerialUnit;
use App\Models\StppTransaction;
use App\Models\User;
use App\Services\DocumentNumberService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * STPP — alat ber-serial diserahkan ke divisi/holder. docs/status-flow.md §8.
 * ACTIVE (butuh out_npbg) -> PASSIVE (ditarik lewat RI / rusak).
 * PASSIVE -> diserahkan lagi = baris STPP baru (serial sama), status ACTIVE.
 */
class StppService
{
    public function __construct(private readonly DocumentNumberService $numbers) {}

    /**
     * @param  array{serial_no?:?string, item_id?:?int, description_raw?:string, qty?:float, unit_id?:?int, holder_id?:?int, holder_name_raw?:?string, placement_department_id?:?int, placement_raw?:?string, out_npbg_id?:?int, out_date?:?string, out_note?:?string}  $data
     */
    public function issue(User $user, array $data): StppTransaction
    {
        $date = Carbon::parse($data['out_date'] ?? now());
        $serial = trim((string) ($data['serial_no'] ?? '')) ?: null;

        return DB::transaction(function () use ($user, $data, $date, $serial) {
            $unit = null;
            if ($serial) {
                $unit = SerialUnit::firstOrCreate(
                    ['serial_no' => $serial, 'kind' => 'STPP_TOOL'],
                    ['item_id' => $data['item_id'] ?? null, 'description_raw' => $data['description_raw'] ?? null],
                );
                $unit->update(['status' => 'IN_USE']);
            }

            return StppTransaction::create([
                'number' => $this->numbers->next('STPP', 'SDA', $date),
                'serial_unit_id' => $unit?->id,
                'serial_no_raw' => $serial,
                'item_id' => $data['item_id'] ?? null,
                'description_raw' => $data['description_raw'] ?? '-',
                'qty' => $data['qty'] ?? 1,
                'unit_id' => $data['unit_id'] ?? null,
                'holder_id' => $data['holder_id'] ?? null,
                'holder_name_raw' => $data['holder_name_raw'] ?? null,
                'placement_department_id' => $data['placement_department_id'] ?? null,
                'placement_raw' => $data['placement_raw'] ?? null,
                'out_npbg_id' => $data['out_npbg_id'] ?? null,
                'out_date' => $date->toDateString(),
                'status' => 'ACTIVE',
                'out_note' => $data['out_note'] ?? null,
                'created_by' => $user->id,
            ]);
        });
    }

    /**
     * @param  array{return_ri_id?:?int, return_date?:?string, return_note?:?string}  $data
     */
    public function withdraw(StppTransaction $stpp, array $data): StppTransaction
    {
        $this->assert($stpp, ['ACTIVE']);

        return DB::transaction(function () use ($stpp, $data) {
            $stpp->update([
                'status' => 'PASSIVE',
                'return_ri_id' => $data['return_ri_id'] ?? null,
                'return_date' => $data['return_date'] ?? now()->toDateString(),
                'return_note' => $data['return_note'] ?? null,
            ]);
            $stpp->serialUnit?->update(['status' => 'IN_STOCK']);

            return $stpp->refresh();
        });
    }

    /** PASSIVE -> serahkan lagi: baris baru, serial sama. */
    public function reissue(StppTransaction $stpp, User $user, array $data): StppTransaction
    {
        $this->assert($stpp, ['PASSIVE']);

        return $this->issue($user, array_merge([
            'serial_no' => $stpp->serial_no_raw,
            'item_id' => $stpp->item_id,
            'description_raw' => $stpp->description_raw,
            'qty' => $stpp->qty,
            'unit_id' => $stpp->unit_id,
        ], $data));
    }

    /** @param list<string> $allowed */
    private function assert(StppTransaction $stpp, array $allowed): void
    {
        if (! in_array($stpp->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => ["Aksi tidak valid untuk status STPP {$stpp->status}."]]);
        }
    }
}
