<?php

namespace Tests\Feature\Accurate;

use App\Models\Item;
use App\Models\SyncBatch;
use App\Models\Unit;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Tests the Laravel-side of the Accurate sync (upsert logic, idempotency,
 * validation, error handling, permissions, sync_logs) against a fixture
 * `accurate_item` table created in the test's own SQLite connection, with
 * Process::fake() standing in for the Python staging step.
 *
 * The real Firebird connectivity and staging refresh (sync-service, live
 * against GUDANGSIG2025.GDB) was validated separately by hand — see
 * docs/sync-architecture.md — not re-proven here, since the test suite runs
 * on SQLite in-memory and has no access to the real GDB or a MySQL server.
 * Per the "don't pretend sync succeeded if the environment can't reach
 * Accurate" principle, this file is explicit about faking only the Firebird
 * step, never the upsert/logging logic itself.
 */
class AccurateSyncTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = RbacSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();

        // Fixture staging table standing in for the real MySQL accurate_item
        // mirror (same column names/shapes as the real Accurate ITEM table).
        if (! Schema::hasTable('accurate_item')) {
            Schema::create('accurate_item', function ($table) {
                $table->string('ITEMNO')->nullable();
                $table->string('ITEMDESCRIPTION')->nullable();
                $table->string('UNIT1')->nullable();
                $table->boolean('SUSPENDED')->nullable();
                $table->decimal('QUANTITY', 14, 2)->nullable();
                $table->decimal('ONORDER', 14, 2)->nullable();
            });
        }

        Process::fake(); // staging refresh step always "succeeds" with no output
    }

    protected function seedAccurateItem(array $row): void
    {
        DB::table('accurate_item')->insert(array_merge([
            'ITEMNO' => null, 'ITEMDESCRIPTION' => null, 'UNIT1' => null,
            'SUSPENDED' => false, 'QUANTITY' => 0, 'ONORDER' => 0,
        ], $row));
    }

    public function test_trigger_requires_permission(): void
    {
        $this->postJson('/api/sync/accurate')->assertUnauthorized();

        $this->actingAsRole('karyawan');
        $this->postJson('/api/sync/accurate')->assertForbidden();
    }

    public function test_new_accurate_item_is_inserted(): void
    {
        Unit::factory()->create(['code' => 'PCS']);
        $this->seedAccurateItem([
            'ITEMNO' => 'NEW-001', 'ITEMDESCRIPTION' => 'Oxygen Tank', 'UNIT1' => 'PCS',
            'QUANTITY' => 100, 'ONORDER' => 0,
        ]);

        $this->actingAsRole('admin_gudang');
        $res = $this->postJson('/api/sync/accurate')->assertCreated();

        $res->assertJsonPath('data.status', 'SUCCESS')
            ->assertJsonPath('data.inserted_records', 1)
            ->assertJsonPath('data.updated_records', 0)
            ->assertJsonPath('data.error_records', 0);

        $item = Item::where('code', 'NEW-001')->firstOrFail();
        $this->assertSame('Oxygen Tank', $item->description);
        $this->assertSame('accurate', $item->source);
        $this->assertEquals(100, $item->accurate_qty_onhand);
        $this->assertNotNull($item->accurate_synced_at);

        $log = DB::table('sync_logs')->where('source_id', 'NEW-001')->first();
        $this->assertSame('INSERT', $log->action);
        $this->assertSame('SUCCESS', $log->status);
    }

    public function test_second_sync_updates_existing_item_without_duplicating(): void
    {
        // §28 of the brief: BRG001 OXYGEN stock 100 -> sync -> 150 -> sync
        // again must UPDATE the one row, never create a second one.
        Unit::factory()->create(['code' => 'PCS']);
        $this->seedAccurateItem([
            'ITEMNO' => 'BRG001', 'ITEMDESCRIPTION' => 'OXYGEN', 'UNIT1' => 'PCS', 'QUANTITY' => 100,
        ]);

        $this->actingAsRole('admin_gudang');
        $this->postJson('/api/sync/accurate')->assertJsonPath('data.inserted_records', 1);

        $this->assertSame(1, Item::where('code', 'BRG001')->count());
        $this->assertEquals(100, Item::where('code', 'BRG001')->value('accurate_qty_onhand'));

        DB::table('accurate_item')->where('ITEMNO', 'BRG001')->update(['QUANTITY' => 150]);

        $res = $this->postJson('/api/sync/accurate')->assertCreated();
        $res->assertJsonPath('data.updated_records', 1)
            ->assertJsonPath('data.inserted_records', 0);

        $this->assertSame(1, Item::where('code', 'BRG001')->count(), 'sync must not create a duplicate row');
        $this->assertEquals(150, Item::where('code', 'BRG001')->value('accurate_qty_onhand'));
    }

    public function test_unchanged_item_is_skipped_on_repeat_sync(): void
    {
        Unit::factory()->create(['code' => 'PCS']);
        $this->seedAccurateItem(['ITEMNO' => 'BRG002', 'ITEMDESCRIPTION' => 'NITROGEN', 'UNIT1' => 'PCS', 'QUANTITY' => 50]);

        $this->actingAsRole('admin_gudang');
        $this->postJson('/api/sync/accurate')->assertJsonPath('data.inserted_records', 1);

        $res = $this->postJson('/api/sync/accurate')->assertCreated();
        $res->assertJsonPath('data.skipped_records', 1)
            ->assertJsonPath('data.inserted_records', 0)
            ->assertJsonPath('data.updated_records', 0);
    }

    public function test_category_placeholder_node_without_unit_is_skipped_not_inserted(): void
    {
        // No UNIT1 — Accurate's PARENTITEM category-tree nodes, not real
        // products (see docs/ACCURATE_MAPPING.md).
        $this->seedAccurateItem(['ITEMNO' => 'CAT.01', 'ITEMDESCRIPTION' => 'SOME CATEGORY', 'UNIT1' => null]);

        $this->actingAsRole('admin_gudang');
        $res = $this->postJson('/api/sync/accurate')->assertCreated();

        $res->assertJsonPath('data.skipped_records', 1)
            ->assertJsonPath('data.inserted_records', 0);
        $this->assertSame(0, Item::where('code', 'CAT.01')->count());
    }

    public function test_row_with_empty_itemno_is_skipped_safely(): void
    {
        $this->seedAccurateItem(['ITEMNO' => '', 'ITEMDESCRIPTION' => 'Broken row', 'UNIT1' => 'PCS']);

        $this->actingAsRole('admin_gudang');
        $res = $this->postJson('/api/sync/accurate')->assertCreated();

        $res->assertJsonPath('data.status', 'SUCCESS')
            ->assertJsonPath('data.skipped_records', 1);
    }

    public function test_unreachable_accurate_database_fails_the_batch_without_crashing(): void
    {
        Process::fake(fn () => Process::result(errorOutput: 'Unable to complete network request to host.', exitCode: 1));

        $this->actingAsRole('admin_gudang');
        $res = $this->postJson('/api/sync/accurate');

        $res->assertStatus(502);
        $res->assertJsonPath('data.status', 'FAILED');
        $this->assertStringContainsString('Unable to connect to Accurate database', $res->json('data.error_message'));
    }

    public function test_sync_history_and_status_endpoints(): void
    {
        Unit::factory()->create(['code' => 'PCS']);
        $this->seedAccurateItem(['ITEMNO' => 'H-001', 'ITEMDESCRIPTION' => 'Test', 'UNIT1' => 'PCS']);

        $this->actingAsRole('admin_gudang');
        $this->postJson('/api/sync/accurate')->assertCreated();

        $this->getJson('/api/sync/status')->assertOk()->assertJsonPath('data.status', 'SUCCESS');

        $history = $this->getJson('/api/sync/history')->assertOk();
        $this->assertGreaterThanOrEqual(1, count($history->json('data')));

        $batch = SyncBatch::first();
        $this->getJson("/api/sync/history/{$batch->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $batch->id)
            ->assertJsonCount(1, 'data.logs');
    }
}
