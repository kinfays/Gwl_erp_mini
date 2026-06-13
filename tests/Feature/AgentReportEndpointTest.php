<?php

namespace Tests\Feature;

use App\Models\AgentReport;
use App\Models\IctAsset;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AgentReportEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_report_requires_bearer_token(): void
    {
        $response = $this->postJson('/api/agent/report', [
            'hostname' => 'host-01',
        ]);

        $response->assertStatus(401);
    }

    public function test_agent_report_matches_asset_and_updates_telemetry(): void
    {
        $permission = Permission::query()->create([
            'name' => 'assets.agent_ingest',
            'display_name' => 'Agent Ingest',
            'module' => 'assets',
            'description' => 'Allows device agent ingestion',
        ]);

        $role = Role::query()->create([
            'name' => User::ROLE_ADMIN,
            'display_name' => 'Admin',
            'description' => 'Admin role',
            'is_system' => true,
        ]);
        $role->permissions()->attach($permission->id);

        $user = User::query()->create([
            'full_name' => 'ICT Bot',
            'staff_id' => 'ICT-0001',
            'email' => 'ict-bot@example.com',
            'password' => Hash::make('secret'),
            'api_token' => 'token-abc-123',
            'is_active' => true,
        ]);
        $user->roles()->attach($role->id);

        $asset = IctAsset::query()->create([
            'asset_name' => 'HQ Laptop 01',
            'serial_number' => 'ABC-123',
            'asset_type' => 'Laptop',
            'status' => 'Active',
        ]);

        $response = $this
            ->withHeader('Authorization', 'Bearer token-abc-123')
            ->postJson('/api/agent/report', [
                'hostname' => 'HQ-LT-01',
                'serial_number' => 'ABC-123',
                'mac_address' => 'AA-BB-CC-11-22-33',
                'os_name' => 'Windows 11 Pro',
                'os_version' => '10.0.22631',
                'cpu_name' => 'Intel(R) Core(TM) i7',
                'ram_gb' => 16,
            ]);

        $response
            ->assertOk()
            ->assertJson([
                'status' => 'ok',
                'asset_id' => $asset->id,
                'matched' => true,
            ]);

        $asset->refresh();

        $this->assertSame('HQ-LT-01', $asset->hostname);
        $this->assertSame('AA-BB-CC-11-22-33', $asset->mac_address);
        $this->assertSame('Windows 11 Pro', $asset->os_name);
        $this->assertSame('10.0.22631', $asset->os_version);
        $this->assertSame('Intel(R) Core(TM) i7', $asset->cpu_name);
        $this->assertNotNull($asset->agent_last_report_at);

        $this->assertDatabaseCount('agent_reports', 1);
        $this->assertTrue((bool) AgentReport::query()->first()?->matched);
    }
}
