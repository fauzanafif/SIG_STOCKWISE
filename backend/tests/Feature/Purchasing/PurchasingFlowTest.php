<?php

namespace Tests\Feature\Purchasing;

use App\Models\Inventory;
use App\Models\Item;
use App\Models\MaterialRequest;
use App\Models\PurchaseOrder;
use App\Models\PurchaseProposal;
use App\Models\Site;
use App\Models\Vendor;
use App\Models\Warehouse;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** brief §AQ — request shortage -> PPB -> PO -> Receiving -> STOCK_IN */
class PurchasingFlowTest extends TestCase
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

    public function test_shortage_request_to_ppb_to_po_to_receiving_stock_in(): void
    {
        $item = Item::factory()->create(['default_warehouse_id' => $this->warehouse->id]);
        Inventory::create([
            'item_id' => $item->id, 'warehouse_id' => $this->warehouse->id,
            'actual_qty' => 5, 'reserved_qty' => 0, 'stock_known' => true,
        ]);

        // request 20 -> shortage 15
        $karyawan = $this->actingAsRole('karyawan', ['site_id' => $this->site->id]);
        $req = MaterialRequest::find($this->postJson('/api/requests', [
            'purpose' => 'butuh 20', 'items' => [['item_id' => $item->id, 'qty_requested' => 20]],
        ])->json('data.id'));
        $this->postJson("/api/requests/{$req->id}/submit");

        $this->actingAsRole('admin_gudang', ['site_id' => $this->site->id]);
        $this->postJson("/api/requests/{$req->id}/review");
        $line = $req->items()->first();
        $this->postJson("/api/requests/{$req->id}/items/{$line->id}/physical-check", ['status' => 'VERIFIED_MATCH']);
        $this->postJson("/api/requests/{$req->id}/reserve")->assertOk()->assertJsonPath('data.status', 'PARTIAL');
        $this->assertSame(15.0, (float) $req->items()->first()->qty_to_purchase);

        // PPB (usulan pembelian internal) from request
        $ppb = $this->postJson('/api/purchase-proposals/from-request', ['material_request_id' => $req->id])
            ->assertCreated()->json('data');
        $this->assertMatchesRegularExpression('#^UPB/NA/\d+/[IVX]+/\d{3}$#', $ppb['number']);
        $this->postJson("/api/purchase-proposals/{$ppb['id']}/submit")->assertOk();

        $this->actingAsRole('purchasing', ['site_id' => $this->site->id]);
        $this->postJson("/api/purchase-proposals/{$ppb['id']}/review");
        $this->postJson("/api/purchase-proposals/{$ppb['id']}/approve")->assertOk()->assertJsonPath('data.status', 'APPROVED');

        $ppbModel = PurchaseProposal::with('items')->find($ppb['id']);
        $ppbLine = $ppbModel->items->first();
        $this->assertSame(15.0, (float) $ppbLine->qty);

        // PO
        $vendor = Vendor::factory()->create();
        $po = $this->postJson('/api/purchase-orders', [
            'vendor_id' => $vendor->id,
            'ppb_id' => $ppb['id'],
            'lines' => [[
                'ppb_item_id' => $ppbLine->id, 'item_id' => $item->id, 'qty' => 15, 'unit_price' => 1000,
            ]],
        ])->assertCreated()->json('data');
        $this->assertSame('15000.00', number_format($po['total'], 2, '.', ''));

        $this->postJson("/api/purchase-orders/{$po['id']}/approve")->assertOk();
        $this->postJson("/api/purchase-orders/{$po['id']}/send")->assertOk()->assertJsonPath('data.status', 'SENT');

        // Receiving — partial first (10), stock NOT added until confirm
        $ri1 = $this->postJson('/api/receivings', [
            'purchase_order_id' => $po['id'],
            'warehouse_id' => $this->warehouse->id,
            'lines' => [['purchase_order_item_id' => $po['items'][0]['id'] ?? $this->firstPoItemId($po['id']),
                'item_id' => $item->id, 'qty_received' => 10]],
        ])->assertCreated()->json('data');

        $this->assertSame(5.0, (float) Inventory::where('item_id', $item->id)->first()->actual_qty);

        $this->postJson("/api/receivings/{$ri1['id']}/confirm")->assertOk()->assertJsonPath('data.status', 'CONFIRMED');
        $this->assertSame(15.0, (float) Inventory::where('item_id', $item->id)->first()->actual_qty);
        $this->assertSame('PARTIAL_RECEIVED', PurchaseOrder::find($po['id'])->status);

        // second receiving (5) -> PO RECEIVED, PPB RECEIVED
        $ri2 = $this->postJson('/api/receivings', [
            'purchase_order_id' => $po['id'],
            'warehouse_id' => $this->warehouse->id,
            'lines' => [['purchase_order_item_id' => $this->firstPoItemId($po['id']),
                'item_id' => $item->id, 'qty_received' => 5]],
        ])->json('data');
        $this->postJson("/api/receivings/{$ri2['id']}/confirm")->assertOk();

        $this->assertSame(20.0, (float) Inventory::where('item_id', $item->id)->first()->actual_qty);
        $this->assertSame('RECEIVED', PurchaseOrder::find($po['id'])->status);
        $this->assertSame('RECEIVED', PurchaseProposal::find($ppb['id'])->status);
        $this->assertDatabaseHas('stock_movements', ['movement_type' => 'RECEIVING']);
    }

    private function firstPoItemId(int $poId): int
    {
        return PurchaseOrder::find($poId)->items()->first()->id;
    }

    public function test_manual_ppb_and_reject(): void
    {
        $this->actingAsRole('admin_gudang', ['site_id' => $this->site->id]);
        $item = Item::factory()->create();

        $ppb = $this->postJson('/api/purchase-proposals', [
            'items' => [['item_id' => $item->id, 'qty' => 3]],
        ])->assertCreated()->json('data');

        $this->postJson("/api/purchase-proposals/{$ppb['id']}/submit")->assertOk();
        $this->postJson("/api/purchase-proposals/{$ppb['id']}/reject", ['reason' => 'tidak jadi'])->assertOk()
            ->assertJsonPath('data.status', 'CANCELLED');
    }

    public function test_ppb_edit_and_delete_only_while_draft(): void
    {
        $this->actingAsRole('admin_gudang', ['site_id' => $this->site->id]);
        $item = Item::factory()->create();

        $ppb = $this->postJson('/api/purchase-proposals', ['items' => [['item_id' => $item->id, 'qty' => 3]]])
            ->assertCreated()->json('data');

        $this->putJson("/api/purchase-proposals/{$ppb['id']}", [
            'notes' => 'revisi qty', 'items' => [['item_id' => $item->id, 'qty' => 5]],
        ])->assertOk()
            ->assertJsonPath('data.notes', 'revisi qty')
            ->assertJsonPath('data.items.0.qty', 5);

        $this->postJson("/api/purchase-proposals/{$ppb['id']}/submit")->assertOk();
        $this->putJson("/api/purchase-proposals/{$ppb['id']}", ['notes' => 'coba edit setelah submit'])->assertStatus(422);
        $this->deleteJson("/api/purchase-proposals/{$ppb['id']}")->assertStatus(422);

        $ppb2 = $this->postJson('/api/purchase-proposals', ['items' => [['item_id' => $item->id, 'qty' => 1]]])
            ->assertCreated()->json('data');
        $this->deleteJson("/api/purchase-proposals/{$ppb2['id']}")->assertOk();
        $this->assertDatabaseMissing('purchase_proposals', ['id' => $ppb2['id']]);
    }

    public function test_karyawan_cannot_approve_ppb(): void
    {
        $ppb = PurchaseProposal::factory()->create(['status' => 'SUBMITTED', 'site_id' => $this->site->id]);
        $this->actingAsRole('karyawan', ['site_id' => $this->site->id]);
        $this->postJson("/api/purchase-proposals/{$ppb->id}/approve")->assertForbidden();
    }
}
