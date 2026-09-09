<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Item extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'code', 'description', 'description_normalized', 'category_id', 'unit_id',
        'item_type', 'needs_blueprint', 'lead_time_days', 'default_warehouse_id',
        'default_location_id', 'blueprint_img_path', 'blueprint_pdf_path',
        'blueprint_3d_ref', 'source', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'needs_blueprint' => 'boolean',
            'is_active' => 'boolean',
            'lead_time_days' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Item $item) {
            $item->description_normalized = self::normalize($item->description);
        });
    }

    public static function normalize(?string $description): string
    {
        return Str::of($description ?? '')
            ->squish()
            ->upper()
            ->replaceMatches('/[^A-Z0-9]+/', ' ')
            ->squish()
            ->value();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(ItemAlias::class);
    }

    public function safetyStocks(): HasMany
    {
        return $this->hasMany(ItemSafetyStock::class);
    }

    public function effectiveSafetyStock(): HasOne
    {
        return $this->hasOne(ItemSafetyStock::class)->where('is_effective', true);
    }

    /** Catalog-wide analysis snapshot (aggregate across warehouses). */
    public function snapshot(): HasOne
    {
        return $this->hasOne(InventorySnapshot::class)->whereNull('warehouse_id');
    }

    public function inventory(): HasMany
    {
        return $this->hasMany(Inventory::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /** Effective safety stock value (0 when none). */
    public function safetyStockValue(): float
    {
        return (float) ($this->relationLoaded('effectiveSafetyStock')
            ? ($this->effectiveSafetyStock?->safety_stock ?? 0)
            : ($this->safetyStocks()->where('is_effective', true)->value('safety_stock') ?? 0));
    }
}
