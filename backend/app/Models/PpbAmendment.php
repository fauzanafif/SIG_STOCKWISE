<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PpbAmendment extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'qty_before' => 'float', 'qty_after' => 'float'];
    }

    public function ppb()
    {
        return $this->belongsTo(Ppb::class);
    }
}
