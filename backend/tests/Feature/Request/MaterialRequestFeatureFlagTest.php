<?php

namespace Tests\Feature\Request;

use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Confirms the material_request feature flag actually blocks the whole
 * /api/requests* surface when off — RequestFlowTest.php (and everything
 * downstream: Goods Issue pickup, Purchasing chain) runs with the flag
 * forced on via phpunit.xml, so this is the only place the "off" behavior
 * itself gets exercised.
 */
class MaterialRequestFeatureFlagTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = RbacSeeder::class;

    public function test_requests_endpoints_are_blocked_with_a_clear_message_when_the_flag_is_off(): void
    {
        config(['stockwise.features.material_request_enabled' => false]);
        $this->actingAsRole('karyawan');

        $res = $this->getJson('/api/requests')->assertForbidden();
        $this->assertStringContainsString('belum diaktifkan', $res->json('message'));

        $this->postJson('/api/requests', ['purpose' => 'test', 'items' => []])->assertForbidden();
    }

    public function test_requests_endpoints_work_again_once_the_flag_is_back_on(): void
    {
        config(['stockwise.features.material_request_enabled' => true]);
        $this->actingAsRole('karyawan');

        $this->getJson('/api/requests')->assertOk();
    }
}
