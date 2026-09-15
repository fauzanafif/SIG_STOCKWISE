<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * NPBG — mirror of Accurate ARINV (header) + ARINVDET (line), one row per line.
 * Populated exclusively by Sync Accurate (App\Services\Accurate\AccurateSyncService);
 * there is no manual create/delete. Only the Stockwise-owned fields below are
 * editable via the API (see App\Http\Controllers\Api\NpbgController::update()).
 */
class Npbg extends Model
{
    protected $table = 'npbg';

    protected $guarded = ['id'];

    /** Fields with no Accurate source yet — the only ones the API allows updating. */
    public const STOCKWISE_OWNED_FIELDS = [
        'tipe_npbg', 'klasifikasi', 'deskripsi', 'nama_proyek', 'no_seri_nopol', 'dikeluarkan_oleh',
    ];

    protected function casts(): array
    {
        return [
            'tgl_npbg' => 'date',
            'shipdate' => 'date',
            'taxdate' => 'date',
            'kuantitas' => 'float',
            'accurate_synced_at' => 'datetime',
        ];
    }

    public function verifications(): HasMany
    {
        return $this->hasMany(NpbgVerification::class);
    }

    public function latestVerification(): HasOne
    {
        return $this->hasOne(NpbgVerification::class)->latestOfMany();
    }
}
