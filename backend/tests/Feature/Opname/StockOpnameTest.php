<?php

namespace Tests\Feature\Opname;

use App\Models\Inventory;
use App\Models\Item;
use App\Models\Site;
use App\Models\StockOpname;
use App\Models\Warehouse;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** brief §AP — TC-SO-001..006 */
class StockOpnameTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = RbacSeeder::class;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $site = Site::factory()->create(['code' => 'SIG-SDA']);
        $this->warehouse = Warehouse::factory()->create(['site_id' => $site->id]);
    }

    private function stockedItem(float $qty): Item
    {
        $item = Item::factory()->create();
        Inventory::create([
            'item_id' => $item->id, 'warehouse_id' => $this->warehouse->id,
            'actual_qty' => $qty, 'reserved_qty' => 0, 'stock_known' => true,
        ]);

        return $item;
    }

    private function scheduleAndStart(): StockOpname
    {
        $this->actingAsRole('admin_gudang');
        $id = $this->postJson('/api/stock-opnames', [
            'warehouse_id' => $this->warehouse->id,
            'scheduled_date' => now()->toDateString(),
            'type' => 'FULL',
        ])->assertCreated()->json('data.id');

        $this->actingAsRole('anak_gudang');
        $this->postJson("/api/stock-opnames/{$id}/start")->assertOk();

        return StockOpname::find($id);
    }

    private function enterCount(StockOpname $o, int $itemId, float $physical, ?string $note = null): void
    {
        $line = $o->items()->where('item_id', $itemId)->first();
        $this->putJson("/api/stock-opnames/{$o->id}/items/{$line->id}", array_filter([
            'physical_qty' => $physical, 'note' => $note,
        ], fn ($v) => $v !== null))->assertOk();
    }

    public function test_tc_so_001_equal_no_note_needed(): void
    {
        $item = $this->stockedItem(100);
        $o = $this->scheduleAndStart();
        $this->enterCount($o, $item->id, 100);

        $this->postJson("/api/stock-opnames/{$o->id}/submit")->assertOk()
            ->assertJsonPath('data.status', 'PENDING_REVIEW');
    }

    public function test_tc_so_002_diff_without_note_rejected(): void
    {
        $item = $this->stockedItem(100);
        $o = $this->scheduleAndStart();
        $this->enterCount($o, $item->id, 95);

        $this->postJson("/api/stock-opnames/{$o->id}/submit")->assertStatus(422)
            ->assertJsonValidationErrors('items');
    }

    public function test_tc_so_003_diff_with_note_ok(): void
    {
        $item = $this->stockedItem(100);
        $o = $this->scheduleAndStart();
        $this->enterCount($o, $item->id, 95, '5 pcs rusak');

        $this->postJson("/api/stock-opnames/{$o->id}/submit")->assertOk()
            ->assertJsonPath('data.status', 'PENDING_REVIEW');
    }

    public function test_tc_so_004_approve_creates_adjustment(): void
    {
        $item = $this->stockedItem(100);
        $o = $this->scheduleAndStart();
        $this->enterCount($o, $item->id, 95, '5 pcs rusak');
        $this->postJson("/api/stock-opnames/{$o->id}/submit");

        $this->actingAsRole('admin_gudang');
        $line = $o->items()->first();
        $this->postJson("/api/stock-opnames/{$o->id}/review", [
            'decisions' => [['id' => $line->id, 'decision' => 'APPROVED']],
        ])->assertOk()->assertJsonPath('data.status', 'COMPLETED');

        $this->assertSame(95.0, (float) Inventory::where('item_id', $item->id)->first()->actual_qty);
        $this->assertDatabaseHas('stock_adjustments', ['qty_before' => 100, 'qty_after' => 95]);
        $this->assertDatabaseHas('stock_movements', ['movement_type' => 'STOCK_ADJUSTMENT']);
    }

    public function test_tc_so_005_reject_no_adjustment(): void
    {
        $item = $this->stockedItem(100);
        $o = $this->scheduleAndStart();
        $this->enterCount($o, $item->id, 95, 'salah hitung');
        $this->postJson("/api/stock-opnames/{$o->id}/submit");

        $this->actingAsRole('admin_gudang');
        $line = $o->items()->first();
        $this->postJson("/api/stock-opnames/{$o->id}/review", [
            'decisions' => [['id' => $line->id, 'decision' => 'REJECTED']],
        ])->assertOk();

        $this->assertSame(100.0, (float) Inventory::where('item_id', $item->id)->first()->actual_qty);
        $this->assertDatabaseCount('stock_adjustments', 0);
    }

    public function test_tc_so_006_recount(): void
    {
        $item = $this->stockedItem(100);
        $o = $this->scheduleAndStart();
        $this->enterCount($o, $item->id, 95, 'ragu');
        $this->postJson("/api/stock-opnames/{$o->id}/submit");

        $this->actingAsRole('admin_gudang');
        $line = $o->items()->first();
        $this->postJson("/api/stock-opnames/{$o->id}/review", [
            'decisions' => [['id' => $line->id, 'decision' => 'RECOUNT']],
        ])->assertOk()->assertJsonPath('data.status', 'RECOUNT_REQUIRED');
    }

    public function test_anak_gudang_cannot_approve(): void
    {
        $o = $this->scheduleAndStart();
        $this->actingAsRole('anak_gudang');
        $this->postJson("/api/stock-opnames/{$o->id}/review", ['decisions' => [['id' => 1, 'decision' => 'APPROVED']]])
            ->assertForbidden();
    }
}
