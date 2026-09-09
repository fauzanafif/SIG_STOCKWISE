<?php

namespace Tests\Feature\Inventory;

use App\Models\Category;
use App\Models\Item;
use App\Models\Unit;
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
}
