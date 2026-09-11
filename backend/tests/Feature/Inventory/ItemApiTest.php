<?php

namespace Tests\Feature\Inventory;

use App\Models\Category;
use App\Models\Item;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ItemApiTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = RbacSeeder::class;

    public function test_index_requires_permission(): void
    {
        $this->getJson('/api/items')->assertUnauthorized();

        $this->actingAsRole('karyawan');
        $this->getJson('/api/items')->assertForbidden();
    }

    public function test_karyawan_can_use_item_lookup_but_not_full_index(): void
    {
        Item::factory()->create(['code' => 'BAN.777', 'description' => 'BAN LUAR STEEL']);
        $this->actingAsRole('karyawan');

        $this->getJson('/api/items')->assertForbidden();
        $this->getJson('/api/items/lookup?search=BAN')->assertOk()
            ->assertJsonPath('data.0.code', 'BAN.777');
        $this->getJson('/api/items/lookup?search=b')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_index_lists_paginated_items(): void
    {
        Item::factory()->count(30)->create();
        $this->actingAsRole('admin_gudang');

        $res = $this->getJson('/api/items?per_page=10')->assertOk();
        $res->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.total', 30)
            ->assertJsonStructure(['data' => [['id', 'code', 'description', 'category', 'unit']], 'meta', 'links']);
    }

    public function test_search_and_filter(): void
    {
        $cat = Category::factory()->create(['name' => 'Automotive', 'path' => 'Automotive']);
        Item::factory()->create(['code' => 'BAN.001', 'description' => 'BAN LUAR STEEL 750', 'category_id' => $cat->id]);
        Item::factory()->create(['code' => 'ATK.001', 'description' => 'PULPEN HITAM']);

        $this->actingAsRole('admin_gudang');

        $this->getJson('/api/items?search=BAN')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'BAN.001');
        $this->getJson('/api/items?category_induk=Automotive')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/items?code=ATK')->assertOk()->assertJsonPath('data.0.code', 'ATK.001');
    }

    public function test_show_returns_detail_with_relations(): void
    {
        $item = Item::factory()->safetyStock(10)->withStock(25)->create();
        $this->actingAsRole('admin_gudang');

        $this->getJson("/api/items/{$item->id}")
            ->assertOk()
            ->assertJsonPath('data.code', $item->code)
            ->assertJsonPath('data.safety_stock', 10)
            ->assertJsonCount(1, 'data.inventory');
    }

    public function test_create_update_delete_flow_and_authorization(): void
    {
        $unit = Unit::factory()->create();
        $payload = ['code' => 'NEW.001', 'description' => 'BARANG BARU', 'unit_id' => $unit->id, 'lead_time_days' => 7];

        // karyawan cannot create
        $this->actingAsRole('karyawan');
        $this->postJson('/api/items', $payload)->assertForbidden();

        // admin_gudang can
        $this->actingAsRole('admin_gudang');
        $create = $this->postJson('/api/items', $payload)->assertCreated();
        $id = $create->json('data.id');
        $this->assertDatabaseHas('items', ['code' => 'NEW.001']);
        // kolom dengan default di DB (item_type, is_active) harus ikut kebawa di response create.
        $create->assertJsonPath('data.item_type', 'CONSUMABLE')->assertJsonPath('data.is_active', true);

        $this->putJson("/api/items/{$id}", ['description' => 'BARANG DIUBAH'])
            ->assertOk()->assertJsonPath('data.description', 'BARANG DIUBAH');

        $this->deleteJson("/api/items/{$id}")->assertOk();
        $this->assertSoftDeleted('items', ['id' => $id]);
    }

    public function test_create_rejects_duplicate_code(): void
    {
        Item::factory()->create(['code' => 'DUP.001']);
        $this->actingAsRole('admin_gudang');

        $this->postJson('/api/items', ['code' => 'DUP.001', 'description' => 'X'])
            ->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_purchasing_can_view_but_not_edit_items(): void
    {
        $item = Item::factory()->create();
        $this->actingAsRole('purchasing');

        $this->getJson("/api/items/{$item->id}")->assertOk();
        $this->putJson("/api/items/{$item->id}", ['description' => 'X'])->assertForbidden();
    }

    /** Master Barang harus lengkap sesuai kolom DATA.xlsx DATABASE UTAMA. */
    public function test_show_exposes_full_data_master_column_set(): void
    {
        $category = Category::create(['name' => 'ASSET', 'level' => 2, 'path' => 'Assets > ASSET', 'is_active' => true]);
        $warehouse = Warehouse::factory()->create(['code' => 'GUDANG 1']);
        $location = WarehouseLocation::create(['warehouse_id' => $warehouse->id, 'code' => 'B.7.1', 'is_active' => true]);
        $item = Item::factory()->create([
            'category_id' => $category->id, 'needs_blueprint' => true,
            'default_warehouse_id' => $warehouse->id, 'default_location_id' => $location->id,
            'blueprint_3d_ref' => 'A.17.31',
        ]);

        $this->actingAsRole('admin_gudang');
        $res = $this->getJson("/api/items/{$item->id}")->assertOk();

        $res->assertJsonPath('data.category_breakdown.induk', 'Assets')
            ->assertJsonPath('data.category_breakdown.anak_1', 'ASSET')
            ->assertJsonPath('data.category_breakdown.anak_2', null)
            ->assertJsonPath('data.needs_blueprint', true)
            ->assertJsonPath('data.default_warehouse.code', 'GUDANG 1')
            ->assertJsonPath('data.default_location.code', 'B.7.1')
            ->assertJsonPath('data.blueprint_3d_ref', 'A.17.31')
            ->assertJsonPath('data.alias_name', 'Tidak')
            ->assertJsonPath('data.aliases', []);
    }

    public function test_update_accepts_blueprint_and_location_fields(): void
    {
        $item = Item::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $location = WarehouseLocation::create(['warehouse_id' => $warehouse->id, 'code' => 'A.1.1', 'is_active' => true]);
        $this->actingAsRole('admin_gudang');

        $this->putJson("/api/items/{$item->id}", [
            'default_warehouse_id' => $warehouse->id,
            'default_location_id' => $location->id,
            'blueprint_3d_ref' => 'C.1.2',
            'blueprint_img_path' => 'blueprints/x.png',
        ])->assertOk()
            ->assertJsonPath('data.default_warehouse.id', $warehouse->id)
            ->assertJsonPath('data.blueprint_3d_ref', 'C.1.2');

        $this->assertDatabaseHas('items', ['id' => $item->id, 'blueprint_img_path' => 'blueprints/x.png']);
    }
}
