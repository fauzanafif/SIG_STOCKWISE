<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MaterialRequest extends Model
{
    use HasFactory;

    public const STATUSES = [
        'DRAFT', 'SUBMITTED', 'UNDER_REVIEW', 'READY', 'PARTIAL', 'NEED_PURCHASE',
        'RESERVED', 'PREPARING', 'READY_TO_PICKUP', 'PICKED_UP', 'COMPLETED', 'CANCELLED',
    ];

    protected $fillable = [
        'number', 'requester_id', 'requester_name', 'requester_wa', 'department_id', 'site_id',
        'purpose', 'notes', 'request_date', 'work_location', 'needed_date', 'status',
        'npbg_no', 'ppb_no', 'request_ip', 'network_label',
        'submitted_at', 'reviewed_by', 'reviewed_at', 'completed_at', 'cancel_reason', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'request_date' => 'date',
            'needed_date' => 'date',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(MaterialRequestItem::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(StockReservation::class);
    }

    public function isEditable(): bool
    {
        return $this->status === 'DRAFT';
    }
}
