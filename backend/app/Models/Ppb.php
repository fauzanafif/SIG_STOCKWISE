<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * PPB — mirror of Accurate REQUISITION (header) + REQUISITIONDET (line), one row
 * per line. Populated exclusively by Sync Accurate (App\Services\Accurate\AccurateSyncService);
 * read-only — no manual create/update/delete, unlike Npbg.
 */
class Ppb extends Model
{
    protected $table = 'ppb';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'tgl_ppb' => 'date',
            'kuantitas' => 'float',
            'qty_dipesan' => 'float',
            'qty_diterima' => 'float',
            'accurate_synced_at' => 'datetime',
        ];
    }
}
