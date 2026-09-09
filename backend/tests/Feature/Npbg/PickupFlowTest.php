<?php

namespace Tests\Feature\Npbg;

use App\Models\Inventory;
use App\Models\Item;
use App\Models\MaterialRequest;
use App\Models\Npbg;
use App\Models\Site;
use App\Models\Warehouse;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * docs/testing-plan.md §Pickup flow / brief §AO — actual stock decreases ONLY after pickup.
 */
class PickupFlowTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = RbacSeeder::class;

    private Site $site;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Site::factory()->create(['code' => 'SIG-SDA']);
        $this->warehouse = Warehouse::factory()->create(['site_id' => $this->site->id]);
    }

    private function reservedRequest(int $actual = 100, float $qty = 5): array
    {
        $item = Item::factory()->create(['default_warehouse_id' => $this->warehouse->id]);
        Inventory::create([
            'item_id' => $item->id, 'warehouse_id' => $this->warehouse->id,
            'actual_qty' => $actual, 'reserved_qty' => 0, 'stock_known' => true,
        ]);

        $karyawan = $this->actingAsRole('karyawan', ['site_id' => $this->site->id]);
        $req = MaterialRequest::find(
            $this->postJson('/api/requests', [
                'purpose' => 'test', 'items' => [['item_id' => $item->id, 'qty_requested' => $qty]],
            ])->json('data.id')
        );
        $this->postJson("/api/requests/{$req->id}/submit");

        $this->actingAsRole('admin_gudang', ['site_id' => $this->site->id]);
        $this->postJson("/api/requests/{$req->id}/review");
        $line = $req->items()->first();
        $this->postJson("/api/requests/{$req->id}/items/{$line->id}/physical-check", ['status' => 'VERIFIED_MATCH']);
        $this->postJson("/api/requests/{$req->id}/reserve");

        return [$req->fresh(), $item];
    }

    public function test_full_pickup_flow_decreases_actual_only_at_pickup(): void
    {
        [$req, $item] = $this->reservedRequest(actual: 100, qty: 5);
        $inv = fn () => Inventory::where('item_id', $item->id)->first();

        $this->assertSame(100.0, (float) $inv()->actual_qty);
        $this->assertSame(5.0, (float) $inv()->reserved_qty);

        // create NPBG from the reserved request
        $npbg = $this->postJson('/api/npbg/from-request', ['material_request_id' => $req->id])
            ->assertCreated()->json('data');
        $this->assertMatchesRegularExpression('#^NPBG/NA/\d+/[IVX]+/\d{3}$#', $npbg['number']);
        $this->assertSame('PREPARING', $npbg['status']);
        $this->assertSame('PREPARING', $req->fresh()->status);

        // still no stock movement
        $this->assertSame(100.0, (float) $inv()->actual_qty);
        $this->assertSame(5.0, (float) $inv()->reserved_qty);

        $this->postJson("/api/npbg/{$npbg['id']}/ready")->assertOk()->assertJsonPath('data.status', 'READY_TO_PICKUP');
        $this->assertSame(100.0, (float) $inv()->actual_qty);

        // pickup — NOW actual drops
        $this->postJson("/api/npbg/{$npbg['id']}/pickup", [
            'picked_up_by' => 'Budi', 'signature' => 'data:image/png;base64,aGVsbG8=',
        ])->assertOk()->assertJsonPath('data.status', 'COMPLETED')->assertJsonPath('data.picked_up_by', 'Budi');

        $this->assertSame(95.0, (float) $inv()->actual_qty);
        $this->assertSame(0.0, (float) $inv()->reserved_qty);
        $this->assertSame(95.0, (float) $inv()->available_qty);

        $this->assertDatabaseHas('stock_movements', ['movement_type' => 'STOCK_OUT', 'qty' => 5]);
        $this->assertDatabaseHas('stock_reservations', ['status' => 'CONSUMED']);
        $this->assertSame('COMPLETED', $req->fresh()->status);
    }

    public function test_manual_npbg_issues_stock_directly_on_pickup(): void
    {
        $item = Item::factory()->create();
        Inventory::create([
            'item_id' => $item->id, 'warehouse_id' => $this->warehouse->id,
            'actual_qty' => 40, 'reserved_qty' => 0, 'stock_known' => true,
        ]);

        $this->actingAsRole('admin_gudang', ['site_id' => $this->site->id]);
        $npbg = $this->postJson('/api/npbg', [
            'warehouse_id' => $this->warehouse->id,
            'classification' => 'UMUM',
            'items' => [['item_id' => $item->id, 'qty' => 10]],
        ])->assertCreated()->json('data');

        $this->postJson("/api/npbg/{$npbg['id']}/ready");
        $this->postJson("/api/npbg/{$npbg['id']}/pickup", ['picked_up_by' => 'Wahyu'])->assertOk();

        $this->assertSame(30.0, (float) Inventory::where('item_id', $item->id)->first()->actual_qty);
    }

    public function test_cancel_npbg_returns_request_to_reserved(): void
    {
        [$req] = $this->reservedRequest();
        $this->actingAsRole('admin_gudang', ['site_id' => $this->site->id]);

        $npbg = $this->postJson('/api/npbg/from-request', ['material_request_id' => $req->id])->json('data');
        $this->postJson("/api/npbg/{$npbg['id']}/cancel", ['reason' => 'salah gudang'])->assertOk()
            ->assertJsonPath('data.status', 'CANCELLED');

        $this->assertSame('RESERVED', $req->fresh()->status);
    }

    public function test_karyawan_cannot_pickup(): void
    {
        $npbg = Npbg::factory()->create(['status' => 'READY_TO_PICKUP', 'warehouse_id' => $this->warehouse->id, 'site_id' => $this->site->id]);
        $this->actingAsRole('karyawan', ['site_id' => $this->site->id]);

        $this->postJson("/api/npbg/{$npbg->id}/pickup", ['picked_up_by' => 'x'])->assertForbidden();
    }
}
