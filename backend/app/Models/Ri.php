<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * RI — mirror of Accurate APINV (header) + APITMDET (item lines), one row per
 * line. Populated exclusively by Sync Accurate (App\Services\Accurate\AccurateSyncService);
 * read-only, same as Ppb.
 */
class Ri extends Model
{
    protected $table = 'ri';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'tgl_ri' => 'date',
            'shipdate' => 'date',
            'kuantitas' => 'float',
            'harga_satuan' => 'float',
            'accurate_synced_at' => 'datetime',
        ];
    }
}
