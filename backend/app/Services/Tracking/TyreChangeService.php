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

    /**
     * Ubah data penggantian ban selagi PENDING_RI (ban lama belum masuk RI).
     *
     * @param  array{position?:?string, new_tyre_desc?:?string, new_serial_raw?:?string, old_tyre_desc?:?string, old_serial_raw?:?string, reason?:?string}  $data
     */
    public function update(TyreChange $change, array $data): TyreChange
    {
        $this->assertEditable($change);

        $change->update([
            'position' => $data['position'] ?? $change->position,
            'new_tyre_desc' => $data['new_tyre_desc'] ?? $change->new_tyre_desc,
            'new_serial_raw' => $data['new_serial_raw'] ?? $change->new_serial_raw,
            'old_tyre_desc' => $data['old_tyre_desc'] ?? $change->old_tyre_desc,
            'old_serial_raw' => $data['old_serial_raw'] ?? $change->old_serial_raw,
            'reason' => $data['reason'] ?? $change->reason,
        ]);

        return $change->refresh();
    }

    /** Hapus baris — hanya selagi PENDING_RI (belum CLEAR). */
    public function delete(TyreChange $change): void
    {
        $this->assertEditable($change);
        $change->delete();
    }

    private function assertEditable(TyreChange $change): void
    {
        if ($change->status !== 'PENDING_RI') {
            throw ValidationException::withMessages(['status' => ['Penggantian ban yang sudah CLEAR tidak bisa diubah/dihapus.']]);
        }
    }
}
