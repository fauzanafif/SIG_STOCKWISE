<?php

namespace Tests\Feature\Tracking;

use App\Models\Asset;
use App\Models\Item;
use App\Models\Site;
use App\Models\Vendor;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** PHASE 8 — modul tracking (Lend/Borrow/STPP/Ban Luar/Maintenance/Manufaktur/Bekas). */
class TrackingFlowTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = RbacSeeder::class;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Site::factory()->create(['code' => 'SIG-SDA']);
    }

    public function test_lend_create_then_partial_and_full_return(): void
    {
        $this->actingAsRole('admin_gudang', ['site_id' => $this->site->id]);
        $item = Item::factory()->create();

        $lend = $this->postJson('/api/lend', [
            'item_id' => $item->id, 'qty' => 10, 'purpose' => 'RELASI',
            'borrower_name' => 'CV Mitra', 'est_days' => 7,
        ])->assertCreated()->json('data');
        $this->assertSame('ON_LOAN', $lend['status']);
        $this->assertNotNull($lend['due_date']);

        $this->postJson("/api/lend/{$lend['id']}/return", ['qty' => 4])
            ->assertOk()->assertJsonPath('data.status', 'PARTIAL_RETURN');
        $this->postJson("/api/lend/{$lend['id']}/return", ['qty' => 6])
            ->assertOk()->assertJsonPath('data.status', 'RETURNED');
        $this->postJson("/api/lend/{$lend['id']}/return", ['qty' => 1])->assertStatus(422);
    }

    public function test_borrow_create_then_return(): void
    {
        $this->actingAsRole('admin_gudang', ['site_id' => $this->site->id]);
        $borrow = $this->postJson('/api/borrow', [
            'description_raw' => 'Trafo las', 'qty' => 2, 'lender_name' => 'Bengkel CDO',
        ])->assertCreated()->json('data');

        $this->postJson("/api/borrow/{$borrow['id']}/return", ['qty' => 2])
            ->assertOk()->assertJsonPath('data.status', 'RETURNED');
    }

    public function test_stpp_issue_withdraw_reissue(): void
    {
        $this->actingAsRole('admin_gudang', ['site_id' => $this->site->id]);
        $item = Item::factory()->create();

        $stpp = $this->postJson('/api/stpp', [
            'serial_no' => 'SN-9001', 'item_id' => $item->id, 'description_raw' => 'Kunci moment',
            'holder_name_raw' => 'Budi',
        ])->assertCreated()->json('data');
        $this->assertSame('ACTIVE', $stpp['status']);

        $this->postJson("/api/stpp/{$stpp['id']}/withdraw", ['return_note' => 'rusak'])
            ->assertOk()->assertJsonPath('data.status', 'PASSIVE');

        $reissued = $this->postJson("/api/stpp/{$stpp['id']}/reissue", ['holder_name_raw' => 'Andi'])
            ->assertCreated()->json('data');
        $this->assertSame('ACTIVE', $reissued['status']);
        $this->assertSame('SN-9001', $reissued['serial_no']);
        $this->assertDatabaseCount('stpp_transactions', 2);
    }

    public function test_tyre_change_pending_then_close(): void
    {
        $this->actingAsRole('admin_gudang', ['site_id' => $this->site->id]);
        $asset = Asset::factory()->create(['site_id' => $this->site->id]);

        $tc = $this->postJson('/api/tyre-changes', [
            'asset_id' => $asset->id, 'position' => 'FRONT_L',
            'new_tyre_desc' => 'GT Radial 750-16', 'new_serial_raw' => '2320611829',
        ])->assertCreated()->json('data');
        $this->assertSame('PENDING_RI', $tc['status']);
        $this->assertSame(1, $tc['change_seq']);

        $this->postJson("/api/tyre-changes/{$tc['id']}/close", [])
            ->assertOk()->assertJsonPath('data.status', 'CLEAR');

        // opening record -> langsung CLEAR
        $op = $this->postJson('/api/tyre-changes', [
            'asset_id' => $asset->id, 'position' => 'SPARE', 'is_opening' => true,
        ])->assertCreated()->json('data');
        $this->assertSame('CLEAR', $op['status']);
    }

    public function test_maintenance_order_sub_rollup_to_completed(): void
    {
        $this->actingAsRole('admin_gudang', ['site_id' => $this->site->id]);
        $asset = Asset::factory()->create(['site_id' => $this->site->id]);

        $order = $this->postJson('/api/maintenance-orders', [
            'asset_id' => $asset->id, 'site_id' => $this->site->id, 'problem_summary' => 'Rem blong',
        ])->assertCreated()->json('data');
        $this->assertSame('OPEN', $order['status']);

        $withSub = $this->postJson("/api/maintenance-orders/{$order['id']}/subs", [
            'problem_detail' => 'Ganti kampas rem', 'workshop_raw' => 'SIG',
        ])->assertOk()->json('data');
        $this->assertSame('ON_GOING', $withSub['status']);
        $subId = $withSub['subs'][0]['id'];

        $this->postJson("/api/maintenance-subs/{$subId}/complete", ['result_note' => 'selesai, normal'])
            ->assertOk()->assertJsonPath('data.status', 'COMPLETED');
    }

    public function test_manufacturing_jasa_requires_vendor(): void
    {
        $this->actingAsRole('admin_gudang', ['site_id' => $this->site->id]);

        $this->postJson('/api/manufacturing-orders', [
            'kind' => 'JASA', 'site_id' => $this->site->id, 'product_name' => 'Bubut as',
        ])->assertStatus(422);

        $vendor = Vendor::factory()->create();
        $this->postJson('/api/manufacturing-orders', [
            'kind' => 'JASA', 'site_id' => $this->site->id, 'vendor_id' => $vendor->id, 'product_name' => 'Bubut as',
        ])->assertCreated()->assertJsonPath('data.kind', 'JASA');
    }

    public function test_used_return_create_then_close(): void
    {
        $this->actingAsRole('admin_gudang', ['site_id' => $this->site->id]);
        $item = Item::factory()->create();

        $ur = $this->postJson('/api/used-returns', [
            'npbg_ref_raw' => 'NA/25/VIII/138',
            'items' => [
                ['item_id' => $item->id, 'qty' => 3, 'condition' => 'REUSABLE', 'into_stock' => true],
                ['description_raw' => 'Baut GI', 'qty' => -2, 'condition' => 'SCRAP'],
            ],
        ])->assertCreated()->json('data');
        $this->assertSame('PENDING', $ur['status']);
        $this->assertSame(2, $ur['items_count']);

        $this->postJson("/api/used-returns/{$ur['id']}/close", [])
            ->assertOk()->assertJsonPath('data.status', 'CLEAR');
    }

    public function test_karyawan_cannot_create_lend(): void
    {
        $this->actingAsRole('karyawan', ['site_id' => $this->site->id]);
        $this->postJson('/api/lend', ['description_raw' => 'x', 'qty' => 1])->assertForbidden();
    }

    /** index + show tiap modul harus 200 (eager-load relasi tidak boleh salah nama). */
    public function test_all_tracking_index_and_show_load_cleanly(): void
    {
        $this->actingAsRole('admin_gudang', ['site_id' => $this->site->id]);
        $item = Item::factory()->create();
        $asset = Asset::factory()->create(['site_id' => $this->site->id]);
        $vendor = Vendor::factory()->create();

        $created = [
            'lend' => $this->postJson('/api/lend', ['item_id' => $item->id, 'qty' => 2, 'borrower_name' => 'X'])->json('data.id'),
            'borrow' => $this->postJson('/api/borrow', ['description_raw' => 'Trafo', 'qty' => 1, 'lender_name' => 'Y'])->json('data.id'),
            'stpp' => $this->postJson('/api/stpp', ['serial_no' => 'SN-1', 'item_id' => $item->id, 'description_raw' => 'Alat', 'holder_name_raw' => 'Z'])->json('data.id'),
            'tyre-changes' => $this->postJson('/api/tyre-changes', ['asset_id' => $asset->id, 'position' => 'FRONT_L'])->json('data.id'),
            'maintenance-orders' => $this->postJson('/api/maintenance-orders', ['asset_id' => $asset->id, 'site_id' => $this->site->id, 'problem_summary' => 'P'])->json('data.id'),
            'manufacturing-orders' => $this->postJson('/api/manufacturing-orders', ['kind' => 'JASA', 'site_id' => $this->site->id, 'vendor_id' => $vendor->id])->json('data.id'),
            'used-returns' => $this->postJson('/api/used-returns', ['items' => [['item_id' => $item->id, 'qty' => 1, 'condition' => 'USED']]])->json('data.id'),
        ];

        foreach ($created as $base => $id) {
            $this->getJson("/api/{$base}")->assertOk()->assertJsonStructure(['data']);
            $this->getJson("/api/{$base}/{$id}")->assertOk()->assertJsonPath('data.id', $id);
        }
    }
}
