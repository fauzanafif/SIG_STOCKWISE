<?php

namespace Tests\Feature\Accurate;

use App\Models\Npbg;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * NPBG = one row per ARINVDET line joined to its ARINV header (see
 * App\Services\Accurate\AccurateSyncService::syncNpbg and
 * database/migrations/2026_09_15_090002_create_npbg_table.php). Fixture
 * accurate_arinv/accurate_arinvdet tables stand in for the real MySQL
 * staging mirror, same shape as verified against the live GDB
 * (docs/GDB_ANALYSIS.md) — column names copied verbatim.
 */
class AccurateNpbgSyncTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = RbacSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('accurate_arinv')) {
            Schema::create('accurate_arinv', function ($table) {
                $table->integer('ARINVOICEID')->nullable();
                $table->string('INVOICENO')->nullable();
                $table->date('INVOICEDATE')->nullable();
                $table->date('SHIPDATE')->nullable();
                $table->date('TAXDATE')->nullable();
                $table->string('PURCHASEORDERNO')->nullable();
                $table->string('SHIPTO1')->nullable();
                $table->string('DESCRIPTION')->nullable();
            });
        }
        if (! Schema::hasTable('accurate_arinvdet')) {
            Schema::create('accurate_arinvdet', function ($table) {
                $table->integer('ARINVOICEID')->nullable();
                $table->integer('SEQ')->nullable();
                $table->string('ITEMOVDESC')->nullable();
                $table->decimal('QUANTITY', 14, 2)->nullable();
                $table->string('ITEMUNIT')->nullable();
                $table->string('ITEMRESERVED1')->nullable();
            });
        }

        Process::fake();
    }

    private function seedInvoice(array $header, array $lines): void
    {
        DB::table('accurate_arinv')->insert(array_merge([
            'ARINVOICEID' => null, 'INVOICENO' => null, 'INVOICEDATE' => null, 'SHIPDATE' => null,
            'TAXDATE' => null, 'PURCHASEORDERNO' => null, 'SHIPTO1' => null, 'DESCRIPTION' => null,
        ], $header));

        foreach ($lines as $line) {
            DB::table('accurate_arinvdet')->insert(array_merge([
                'ARINVOICEID' => $header['ARINVOICEID'], 'SEQ' => null, 'ITEMOVDESC' => null,
                'QUANTITY' => null, 'ITEMUNIT' => null, 'ITEMRESERVED1' => null,
            ], $line));
        }
    }

    public function test_sync_maps_arinv_and_arinvdet_fields_correctly(): void
    {
        $this->seedInvoice(
            ['ARINVOICEID' => 9, 'INVOICENO' => 'ATK/25/IX/029', 'INVOICEDATE' => '2025-09-20',
                'SHIPDATE' => '2025-09-20', 'TAXDATE' => '2025-09-20', 'PURCHASEORDERNO' => 'GUDANG',
                'SHIPTO1' => 'GUDANG (SDA)', 'DESCRIPTION' => 'UNTUK PEMAKAIAN HARIAN'],
            [['SEQ' => 1, 'ITEMOVDESC' => 'KERTAS HVS A4', 'QUANTITY' => 2, 'ITEMUNIT' => 'RIM', 'ITEMRESERVED1' => 'NABILA']]
        );

        $this->actingAsRole('admin_gudang');
        $res = $this->postJson('/api/sync/accurate')->assertCreated();
        $res->assertJsonPath('data.status', 'SUCCESS')->assertJsonPath('data.inserted_records', 1);

        $npbg = Npbg::where('accurate_arinvoice_id', 9)->where('accurate_seq', 1)->firstOrFail();
        $this->assertSame('ATK/25/IX/029', $npbg->no_npbg);
        $this->assertSame('2025-09-20', $npbg->tgl_npbg->toDateString());
        $this->assertSame('2025-09-20', $npbg->shipdate->toDateString());
        $this->assertSame('2025-09-20', $npbg->taxdate->toDateString());
        $this->assertSame('GUDANG', $npbg->divisi);
        $this->assertSame('GUDANG (SDA)', $npbg->pelanggan);
        $this->assertSame('UNTUK PEMAKAIAN HARIAN', $npbg->keterangan);
        $this->assertSame('KERTAS HVS A4', $npbg->deskripsi_barang);
        $this->assertEquals(2, $npbg->kuantitas);
        $this->assertSame('RIM', $npbg->satuan);
        $this->assertSame('NABILA', $npbg->peminta);

        // fields with no Accurate source stay null
        $this->assertNull($npbg->tipe_npbg);
        $this->assertNull($npbg->klasifikasi);
        $this->assertNull($npbg->deskripsi);
        $this->assertNull($npbg->nama_proyek);
        $this->assertNull($npbg->no_seri_nopol);
        $this->assertNull($npbg->dikeluarkan_oleh);
    }

    public function test_one_invoice_with_multiple_detail_lines_creates_multiple_npbg_rows(): void
    {
        $this->seedInvoice(
            ['ARINVOICEID' => 9, 'INVOICENO' => 'NPBG001'],
            [
                ['SEQ' => 1, 'ITEMOVDESC' => 'Barang A', 'QUANTITY' => 10, 'ITEMUNIT' => 'PCS'],
                ['SEQ' => 2, 'ITEMOVDESC' => 'Barang B', 'QUANTITY' => 5, 'ITEMUNIT' => 'PCS'],
            ]
        );

        $this->actingAsRole('admin_gudang');
        $res = $this->postJson('/api/sync/accurate')->assertCreated();
        $res->assertJsonPath('data.inserted_records', 2);

        $rows = Npbg::where('accurate_arinvoice_id', 9)->orderBy('accurate_seq')->get();
        $this->assertCount(2, $rows);
        $this->assertSame('NPBG001', $rows[0]->no_npbg);
        $this->assertSame('NPBG001', $rows[1]->no_npbg);
        $this->assertSame('Barang A', $rows[0]->deskripsi_barang);
        $this->assertSame('Barang B', $rows[1]->deskripsi_barang);
    }

    public function test_second_sync_updates_without_duplicating(): void
    {
        $this->seedInvoice(['ARINVOICEID' => 20, 'INVOICENO' => 'A/1'], [['SEQ' => 1, 'QUANTITY' => 10]]);

        $this->actingAsRole('admin_gudang');
        $this->postJson('/api/sync/accurate')->assertJsonPath('data.inserted_records', 1);
        $this->assertSame(1, Npbg::where('accurate_arinvoice_id', 20)->count());

        DB::table('accurate_arinvdet')->where('ARINVOICEID', 20)->update(['QUANTITY' => 15]);

        $res = $this->postJson('/api/sync/accurate')->assertCreated();
        $res->assertJsonPath('data.updated_records', 1)->assertJsonPath('data.inserted_records', 0);

        $this->assertSame(1, Npbg::where('accurate_arinvoice_id', 20)->count(), 'sync must not duplicate the row');
        $this->assertEquals(15, Npbg::where('accurate_arinvoice_id', 20)->first()->kuantitas);
    }

    public function test_unchanged_row_is_skipped_on_repeat_sync(): void
    {
        $this->seedInvoice(['ARINVOICEID' => 21, 'INVOICENO' => 'A/2'], [['SEQ' => 1, 'QUANTITY' => 3]]);

        $this->actingAsRole('admin_gudang');
        $this->postJson('/api/sync/accurate')->assertJsonPath('data.inserted_records', 1);

        $res = $this->postJson('/api/sync/accurate')->assertCreated();
        $res->assertJsonPath('data.skipped_records', 1)
            ->assertJsonPath('data.inserted_records', 0)
            ->assertJsonPath('data.updated_records', 0);
    }

    public function test_resync_does_not_erase_stockwise_owned_fields(): void
    {
        // brief §11: sync must never null out user-filled Stockwise-owned data.
        $this->seedInvoice(['ARINVOICEID' => 30, 'INVOICENO' => 'A/3'], [['SEQ' => 1, 'QUANTITY' => 1]]);

        $this->actingAsRole('admin_gudang');
        $this->postJson('/api/sync/accurate')->assertJsonPath('data.inserted_records', 1);

        $npbg = Npbg::where('accurate_arinvoice_id', 30)->firstOrFail();
        $this->patchJson("/api/npbg/{$npbg->id}", ['nama_proyek' => 'Project ABC', 'klasifikasi' => 'KHUSUS'])
            ->assertOk()
            ->assertJsonPath('data.nama_proyek', 'Project ABC');

        // force a change on the Accurate side so the row is re-written, not skipped
        DB::table('accurate_arinvdet')->where('ARINVOICEID', 30)->update(['QUANTITY' => 2]);
        $this->postJson('/api/sync/accurate')->assertJsonPath('data.updated_records', 1);

        $npbg->refresh();
        $this->assertSame('Project ABC', $npbg->nama_proyek);
        $this->assertSame('KHUSUS', $npbg->klasifikasi);
        $this->assertEquals(2, $npbg->kuantitas);
    }

    public function test_update_rejects_accurate_owned_fields(): void
    {
        $this->seedInvoice(['ARINVOICEID' => 40, 'INVOICENO' => 'A/4'], [['SEQ' => 1, 'QUANTITY' => 1]]);
        $this->actingAsRole('admin_gudang');
        $this->postJson('/api/sync/accurate')->assertJsonPath('data.inserted_records', 1);
        $npbg = Npbg::where('accurate_arinvoice_id', 40)->firstOrFail();

        $this->patchJson("/api/npbg/{$npbg->id}", ['no_npbg' => 'HACKED', 'dikeluarkan_oleh' => 'Budi'])
            ->assertOk()
            ->assertJsonPath('data.dikeluarkan_oleh', 'Budi');

        $npbg->refresh();
        $this->assertSame('A/4', $npbg->no_npbg, 'no_npbg is Accurate-owned and must not change via the API');
    }

    public function test_search_and_filter(): void
    {
        $this->seedInvoice(['ARINVOICEID' => 50, 'INVOICENO' => 'SEARCHME/01', 'PURCHASEORDERNO' => 'GUDANG'],
            [['SEQ' => 1, 'ITEMRESERVED1' => 'BUDI']]);
        $this->seedInvoice(['ARINVOICEID' => 51, 'INVOICENO' => 'OTHER/01', 'PURCHASEORDERNO' => 'ACCOUNTING'],
            [['SEQ' => 1, 'ITEMRESERVED1' => 'SITI']]);

        $this->actingAsRole('admin_gudang');
        $this->postJson('/api/sync/accurate')->assertJsonPath('data.inserted_records', 2);

        $this->getJson('/api/npbg?search=SEARCHME')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/npbg?search=BUDI')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/npbg?divisi=ACCOUNTING')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_view_requires_permission(): void
    {
        $this->getJson('/api/npbg')->assertUnauthorized();

        $this->actingAsRole('karyawan');
        $this->getJson('/api/npbg')->assertForbidden();
    }

    public function test_missing_accurate_staging_tables_does_not_break_item_sync(): void
    {
        Schema::dropIfExists('accurate_arinvdet');
        Schema::dropIfExists('accurate_arinv');

        $this->actingAsRole('admin_gudang');
        $res = $this->postJson('/api/sync/accurate')->assertCreated();
        $res->assertJsonPath('data.status', 'SUCCESS')->assertJsonPath('data.total_records', 0);
    }
}
