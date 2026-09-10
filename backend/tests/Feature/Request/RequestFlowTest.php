<?php

namespace Tests\Feature\Request;

use App\Models\Inventory;
use App\Models\Item;
use App\Models\MaterialRequest;
use App\Models\Site;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RequestFlowTest extends TestCase
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

    private function karyawan(): User
    {
        return $this->actingAsRole('karyawan', ['site_id' => $this->site->id]);
    }

    private function stockedItem(float $actual, float $ss = 0): Item
    {
        $item = Item::factory()->create(['default_warehouse_id' => $this->warehouse->id]);
        if ($ss > 0) {
            $item->safetyStocks()->create(['safety_stock' => $ss, 'is_effective' => true]);
        }
        Inventory::create([
            'item_id' => $item->id, 'warehouse_id' => $this->warehouse->id,
            'actual_qty' => $actual, 'reserved_qty' => 0, 'stock_known' => true,
        ]);

        return $item;
    }

    private function createRequest(User $user, array $lines): MaterialRequest
    {
        $res = $this->postJson('/api/requests', [
            'purpose' => 'Perbaikan pompa',
            'items' => $lines,
        ])->assertCreated();

        return MaterialRequest::find($res->json('data.id'));
    }

    public function test_tc_req_001_employee_creates_and_submits(): void
    {
        $user = $this->karyawan();
        $item = $this->stockedItem(100);

        $req = $this->createRequest($user, [['item_id' => $item->id, 'qty_requested' => 5]]);
        $this->assertSame('DRAFT', $req->status);
        $this->assertMatchesRegularExpression('#^REQ/SDA/\d+/[IVX]+/\d{3}$#', $req->number);

        $this->postJson("/api/requests/{$req->id}/submit")->assertOk()
            ->assertJsonPath('data.status', 'SUBMITTED')
            ->assertJsonPath('data.items.0.system_stock_snapshot', 100)
            ->assertJsonPath('data.items.0.projected_stock', 95);
    }

    public function test_request_form_fields_tanggal_peminta_uom(): void
    {
        $user = $this->karyawan();
        $item = $this->stockedItem(100);

        $res = $this->postJson('/api/requests', [
            'purpose' => 'butuh spare part',
            'request_date' => '2026-09-01',
            'requester_name' => 'Pak Slamet (Produksi)',
            'items' => [
                ['item_id' => $item->id, 'qty_requested' => 3],
                ['description_raw' => 'Majun', 'qty_requested' => 2],
            ],
        ])->assertCreated();

        $res->assertJsonPath('data.request_date', '2026-09-01')
            ->assertJsonPath('data.requester_name', 'Pak Slamet (Produksi)')
            ->assertJsonPath('data.npbg_no', null)
            ->assertJsonPath('data.ppb_no', null);
        // UOM baris item ikut master barang otomatis
        $this->assertNotNull(MaterialRequest::find($res->json('data.id'))->items()->whereNotNull('unit_id')->first());
    }

    public function test_request_auto_fills_whatsapp_and_network_label(): void
    {
        config(['stockwise.office.ip_ranges' => ['127.0.0.1', '10.0.0.0/8']]);

        $user = $this->actingAsRole('karyawan', ['site_id' => $this->site->id, 'phone' => '0877-1111-2222']);
        $item = $this->stockedItem(50);

        // TestCase memakai IP 127.0.0.1 → OFFICE
        $res = $this->postJson('/api/requests', [
            'purpose' => 'butuh barang',
            'notes' => 'ditunggu sore ini',
            'items' => [['item_id' => $item->id, 'qty_requested' => 2]],
        ])->assertCreated();

        $res->assertJsonPath('data.requester_wa', '0877-1111-2222')
            ->assertJsonPath('data.notes', 'ditunggu sore ini')
            ->assertJsonPath('data.network_label', 'OFFICE')
            ->assertJsonPath('data.network_label_text', 'Jaringan Kantor');
        $this->assertNotNull($res->json('data.request_ip'));
    }

    public function test_only_admin_gudang_sets_npbg_and_ppb_no(): void
    {
        $user = $this->karyawan();
        $item = $this->stockedItem(100);
        $req = $this->createRequest($user, [['item_id' => $item->id, 'qty_requested' => 5]]);

        // peminta tidak boleh isi No NPBG / Nomor PPB
        $this->patchJson("/api/requests/{$req->id}/refs", ['npbg_no' => 'NA/25/IX/999'])
            ->assertForbidden();

        $this->actingAsRole('admin_gudang', ['site_id' => $this->site->id]);
        $this->patchJson("/api/requests/{$req->id}/refs", [
            'npbg_no' => 'NA/25/IX/999', 'ppb_no' => 'PPB/NA/25/IX/010',
        ])->assertOk()
            ->assertJsonPath('data.npbg_no', 'NA/25/IX/999')
            ->assertJsonPath('data.ppb_no', 'PPB/NA/25/IX/010');
    }

    public function test_tc_req_002_003_review_then_reserve_when_stock_sufficient(): void
    {
        $karyawan = $this->karyawan();
        $item = $this->stockedItem(100);
        $req = $this->createRequest($karyawan, [['item_id' => $item->id, 'qty_requested' => 5]]);
        $this->postJson("/api/requests/{$req->id}/submit")->assertOk();

        $this->actingAsRole('admin_gudang', ['site_id' => $this->site->id]);
        $this->postJson("/api/requests/{$req->id}/review")->assertOk()
            ->assertJsonPath('data.status', 'UNDER_REVIEW');

        $line = $req->items()->first();
        $this->postJson("/api/requests/{$req->id}/items/{$line->id}/physical-check", [
            'status' => 'VERIFIED_MATCH', 'qty' => 100,
        ])->assertOk();

        $this->postJson("/api/requests/{$req->id}/reserve")->assertOk()
            ->assertJsonPath('data.status', 'RESERVED')
            ->assertJsonPath('data.items.0.line_status', 'RESERVED')
            ->assertJsonPath('data.items.0.qty_reserved', 5);

        // stock: actual unchanged, reserved +5 (ATURAN MUTLAK 6/7)
        $inv = Inventory::where('item_id', $item->id)->first();
        $this->assertSame(100.0, (float) $inv->actual_qty);
        $this->assertSame(5.0, (float) $inv->reserved_qty);
        $this->assertSame(95.0, (float) $inv->available_qty);

        $this->assertDatabaseHas('stock_movements', ['movement_type' => 'RESERVATION', 'qty' => 5]);
        $this->assertDatabaseHas('stock_reservations', ['qty' => 5, 'status' => 'ACTIVE']);
    }

    public function test_tc_req_004_reserve_partial_and_need_purchase_when_short(): void
    {
        $karyawan = $this->karyawan();
        $item = $this->stockedItem(3);
        $req = $this->createRequest($karyawan, [['item_id' => $item->id, 'qty_requested' => 10]]);
        $this->postJson("/api/requests/{$req->id}/submit")->assertOk();

        $this->actingAsRole('admin_gudang', ['site_id' => $this->site->id]);
        $this->postJson("/api/requests/{$req->id}/review")->assertOk();
        $line = $req->items()->first();
        $this->postJson("/api/requests/{$req->id}/items/{$line->id}/physical-check", ['status' => 'VERIFIED_MATCH'])->assertOk();

        $this->postJson("/api/requests/{$req->id}/reserve")->assertOk()
            ->assertJsonPath('data.status', 'PARTIAL')
            ->assertJsonPath('data.items.0.line_status', 'PARTIAL')
            ->assertJsonPath('data.items.0.qty_reserved', 3)
            ->assertJsonPath('data.items.0.qty_to_purchase', 7);
    }

    public function test_projected_below_safety_sets_warning(): void
    {
        $user = $this->karyawan();
        $item = $this->stockedItem(7, ss: 5);
        $req = $this->createRequest($user, [['item_id' => $item->id, 'qty_requested' => 5]]);

        $this->postJson("/api/requests/{$req->id}/submit")->assertOk()
            ->assertJsonPath('data.items.0.below_safety_flag', true)
            ->assertJsonPath('data.items.0.warning', 'Request ini akan menyebabkan stok berada di bawah Safety Stock.');
    }

    public function test_physical_mismatch_requires_note_and_blocks_reserve(): void
    {
        $karyawan = $this->karyawan();
        $item = $this->stockedItem(100);
        $req = $this->createRequest($karyawan, [['item_id' => $item->id, 'qty_requested' => 5]]);
        $this->postJson("/api/requests/{$req->id}/submit")->assertOk();

        $this->actingAsRole('admin_gudang', ['site_id' => $this->site->id]);
        $this->postJson("/api/requests/{$req->id}/review")->assertOk();
        $line = $req->items()->first();

        $this->postJson("/api/requests/{$req->id}/items/{$line->id}/physical-check", ['status' => 'VERIFIED_MISMATCH'])
            ->assertStatus(422)->assertJsonValidationErrors('note');

        $this->postJson("/api/requests/{$req->id}/items/{$line->id}/physical-check", [
            'status' => 'VERIFIED_MISMATCH', 'qty' => 40, 'note' => 'Fisik 40, sistem 100',
        ])->assertOk();

        $this->postJson("/api/requests/{$req->id}/reserve")->assertOk()
            ->assertJsonPath('data.items.0.line_status', 'PENDING');
        $this->assertDatabaseMissing('stock_reservations', ['material_request_id' => $req->id, 'status' => 'ACTIVE']);
    }

    public function test_cancel_releases_reservations(): void
    {
        $karyawan = $this->karyawan();
        $item = $this->stockedItem(100);
        $req = $this->createRequest($karyawan, [['item_id' => $item->id, 'qty_requested' => 5]]);
        $this->postJson("/api/requests/{$req->id}/submit")->assertOk();

        $admin = $this->actingAsRole('admin_gudang', ['site_id' => $this->site->id]);
        $this->postJson("/api/requests/{$req->id}/review")->assertOk();
        $line = $req->items()->first();
        $this->postJson("/api/requests/{$req->id}/items/{$line->id}/physical-check", ['status' => 'VERIFIED_MATCH'])->assertOk();
        $this->postJson("/api/requests/{$req->id}/reserve")->assertOk();

        $this->postJson("/api/requests/{$req->id}/cancel", ['reason' => 'Dibatalkan user'])->assertOk()
            ->assertJsonPath('data.status', 'CANCELLED');

        $inv = Inventory::where('item_id', $item->id)->first();
        $this->assertSame(0.0, (float) $inv->reserved_qty);
        $this->assertDatabaseHas('stock_movements', ['movement_type' => 'RELEASE_RESERVATION']);
        $this->assertDatabaseHas('stock_reservations', ['material_request_id' => $req->id, 'status' => 'RELEASED']);
    }

    public function test_karyawan_only_sees_own_requests(): void
    {
        $a = $this->karyawan();
        $this->createRequest($a, [['description_raw' => 'Barang A', 'qty_requested' => 1]]);

        $b = $this->actingAsRole('karyawan', ['site_id' => $this->site->id]);
        $this->createRequest($b, [['description_raw' => 'Barang B', 'qty_requested' => 1]]);

        $this->getJson('/api/requests')->assertOk()->assertJsonCount(1, 'data');

        $this->actingAsRole('admin_gudang', ['site_id' => $this->site->id]);
        $this->getJson('/api/requests')->assertOk()->assertJsonCount(2, 'data');
    }
}
