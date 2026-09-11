<?php

namespace App\Services\Tracking;

use App\Models\LendTransaction;
use App\Models\User;
use App\Services\DocumentNumberService;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Lend — barang SIG dipinjamkan ke relasi/proyek. docs/status-flow.md §8.
 * ON_LOAN -> PARTIAL_RETURN -> RETURNED (via RI kembali, source LEND_RETURN); OVERDUE bila lewat due_date.
 * Modul ini melacak lifecycle; pergerakan stok terjadi di NPBG keluar / RI kembali.
 */
class LendService
{
    public function __construct(private readonly DocumentNumberService $numbers) {}

    /**
     * @param  array{item_id?:?int, description_raw?:string, qty:float, unit_id?:?int, purpose?:string, borrower_name?:?string, customer_id?:?int, project_id?:?int, est_days?:?int, out_npbg_id?:?int, out_date?:?string, condition_out?:?string}  $data
     */
    public function create(User $user, array $data): LendTransaction
    {
        $outDate = Carbon::parse($data['out_date'] ?? now());
        $est = $data['est_days'] ?? null;

        return LendTransaction::create([
            'qty_returned' => 0,
            'number' => $this->numbers->next('LEND', 'SDA', $outDate),
            'item_id' => $data['item_id'] ?? null,
            'description_raw' => $data['description_raw'] ?? '-',
            'qty' => $data['qty'],
            'unit_id' => $data['unit_id'] ?? null,
            'purpose' => $data['purpose'] ?? 'RELASI',
            'borrower_name' => $data['borrower_name'] ?? null,
            'customer_id' => $data['customer_id'] ?? null,
            'project_id' => $data['project_id'] ?? null,
            'est_days' => $est,
            'out_npbg_id' => $data['out_npbg_id'] ?? null,
            'out_date' => $outDate->toDateString(),
            'due_date' => $est ? $outDate->copy()->addDays($est)->toDateString() : null,
            'status' => 'ON_LOAN',
            'condition_out' => $data['condition_out'] ?? null,
            'created_by' => $user->id,
        ]);
    }

    /**
     * @param  array{qty:float, return_ri_id?:?int, return_date?:?string, condition_in?:?string}  $data
     */
    public function recordReturn(LendTransaction $lend, array $data): LendTransaction
    {
        $this->assert($lend, ['ON_LOAN', 'PARTIAL_RETURN', 'OVERDUE']);
        $qty = (float) $data['qty'];
        if ($qty <= 0) {
            throw ValidationException::withMessages(['qty' => ['Qty kembali harus > 0.']]);
        }
        $returned = (float) $lend->qty_returned + $qty;
        if ($returned > (float) $lend->qty + 1e-6) {
            throw ValidationException::withMessages(['qty' => ['Qty kembali melebihi qty dipinjam.']]);
        }

        $full = $returned >= (float) $lend->qty - 1e-6;
        $lend->update([
            'qty_returned' => $returned,
            'return_ri_id' => $data['return_ri_id'] ?? $lend->return_ri_id,
            'return_date' => $data['return_date'] ?? now()->toDateString(),
            'condition_in' => $data['condition_in'] ?? $lend->condition_in,
            'status' => $full ? 'RETURNED' : 'PARTIAL_RETURN',
        ]);

        return $lend->refresh();
    }

    /**
     * Ubah data Lend selagi belum ada pengembalian sama sekali — untuk memperbaiki salah entri.
     *
     * @param  array{item_id?:?int, description_raw?:?string, qty?:float, unit_id?:?int, purpose?:string, borrower_name?:?string, customer_id?:?int, project_id?:?int, est_days?:?int, out_date?:?string, condition_out?:?string}  $data
     */
    public function update(LendTransaction $lend, array $data): LendTransaction
    {
        $this->assertEditable($lend);

        $outDate = isset($data['out_date']) ? Carbon::parse($data['out_date']) : $lend->out_date;
        $est = array_key_exists('est_days', $data) ? $data['est_days'] : $lend->est_days;

        $lend->update([
            'item_id' => $data['item_id'] ?? $lend->item_id,
            'description_raw' => $data['description_raw'] ?? $lend->description_raw,
            'qty' => $data['qty'] ?? $lend->qty,
            'unit_id' => $data['unit_id'] ?? $lend->unit_id,
            'purpose' => $data['purpose'] ?? $lend->purpose,
            'borrower_name' => $data['borrower_name'] ?? $lend->borrower_name,
            'customer_id' => $data['customer_id'] ?? $lend->customer_id,
            'project_id' => $data['project_id'] ?? $lend->project_id,
            'est_days' => $est,
            'out_date' => $outDate->toDateString(),
            'due_date' => $est ? $outDate->copy()->addDays($est)->toDateString() : null,
            'condition_out' => $data['condition_out'] ?? $lend->condition_out,
        ]);

        return $lend->refresh();
    }

    /** Hapus baris Lend — hanya selagi belum ada pengembalian yang tercatat. */
    public function delete(LendTransaction $lend): void
    {
        $this->assertEditable($lend);
        $lend->delete();
    }

    private function assertEditable(LendTransaction $lend): void
    {
        if ($lend->status !== 'ON_LOAN' || (float) $lend->qty_returned > 0) {
            throw ValidationException::withMessages([
                'status' => ['Lend yang sudah ada pengembalian tidak bisa diubah/dihapus — hanya bisa dilihat.'],
            ]);
        }
    }

    /** Job harian menandai pinjaman lewat tempo. */
    public function markOverdue(): int
    {
        return LendTransaction::whereIn('status', ['ON_LOAN', 'PARTIAL_RETURN'])
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', now())
            ->update(['status' => 'OVERDUE']);
    }

    /** @param list<string> $allowed */
    private function assert(LendTransaction $lend, array $allowed): void
    {
        if (! in_array($lend->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => ["Aksi tidak valid untuk status Lend {$lend->status}."]]);
        }
    }
}
