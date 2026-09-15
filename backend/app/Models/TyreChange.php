<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TyreChange extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['change_date' => 'date', 'in_date' => 'date', 'is_opening' => 'boolean'];
    }

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function outNpbg()
    {
        return $this->belongsTo(GoodsIssue::class, 'out_npbg_id');
    }

    public function inRi()
    {
        return $this->belongsTo(Receiving::class, 'in_ri_id');
    }
}
