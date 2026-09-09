<?php

namespace App\Services\Tracking;

use App\Models\TyreChange;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Ban Luar — penggantian ban di 1 posisi pada 1 asset. docs/status-flow.md §8.
 * PENDING_RI -> CLEAR (ban lama masuk lewat RI). is_opening=true -> langsung CLEAR.
 */
class TyreChangeService
{
    /**
     * @param  array{asset_id:int, change_date?:?string, position?:?string, out_npbg_id?:?int, new_tyre_desc?:?string, new_serial_raw?:?string, old_tyre_desc?:?string, old_serial_raw?:?string, reason?:?string, is_opening?:bool}  $data
     */
    public function record(User $user, array $data): TyreChange
    {
        $date = Carbon::parse($data['change_date'] ?? now());
        $opening = (bool) ($data['is_opening'] ?? false);

        $seq = TyreChange::where('asset_id', $data['asset_id'])
            ->where('position', $data['position'] ?? null)
            ->count() + 1;

        return TyreChange::create([
            'asset_id' => $data['asset_id'],
            'change_date' => $date->toDateString(),
            'position' => $data['position'] ?? null,
            'change_seq' => $seq,
            'out_npbg_id' => $data['out_npbg_id'] ?? null,
            'new_tyre_desc' => $data['new_tyre_desc'] ?? null,
            'new_serial_raw' => $data['new_serial_raw'] ?? null,
            'old_tyre_desc' => $data['old_tyre_desc'] ?? null,
            'old_serial_raw' => $data['old_serial_raw'] ?? null,
            'reason' => $data['reason'] ?? null,
            'is_opening' => $opening,
            'status' => $opening ? 'CLEAR' : 'PENDING_RI',
            'in_date' => $opening ? $date->toDateString() : null,
            'created_by' => $user->id,
        ]);
    }

    /**
     * @param  array{in_ri_id?:?int, in_date?:?string}  $data
     */
    public function close(TyreChange $change, array $data): TyreChange
    {
        if ($change->status === 'CLEAR') {
            throw ValidationException::withMessages(['status' => ['Penggantian ban sudah CLEAR.']]);
        }
        $change->update([
            'status' => 'CLEAR',
            'in_ri_id' => $data['in_ri_id'] ?? null,
            'in_date' => $data['in_date'] ?? now()->toDateString(),
        ]);

        return $change->refresh();
    }
}
