<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Ppb extends Model
{
    use HasFactory;

    protected $table = 'ppb';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'approved_at' => 'datetime'];
    }

    public function items()
    {
        return $this->hasMany(PpbItem::class);
    }

    public function amendments()
    {
        return $this->hasMany(PpbAmendment::class);
    }

    public function sourceRequest()
    {
        return $this->belongsTo(MaterialRequest::class, 'source_request_id');
    }

    public function site()
    {
        return $this->belongsTo(Site::class);
    }
}
