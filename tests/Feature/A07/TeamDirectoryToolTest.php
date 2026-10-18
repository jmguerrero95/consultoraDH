<?php

declare(strict_types=1);

namespace Tests\Feature\A07;

use App\Domain\Ai\AiToolRejected;
use App\Models\Client;
use App\Models\User;
use App\Services\Ai\AiToolRegistry;
use App\Services\Ai\Tools\TeamDirectoryTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * A07-R5-R2 (bloque E3) — the responsible party is resolved by name.
 *
 * The assistant asked the person for "María's id", a number only a developer
 * knows. This tool answers with the same list the task form's picker offers:
 * active staff, by name and id, under `tasks.view`.
 */
final class TeamDirectoryToolTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['ai.use', 'tasks.view', 'tasks.create'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $this->staff = User::factory()->create(['name' => 'Ana Coordinadora', 'account_type' => 'staff']);
        $this->staff->givePermissionTo(['ai.use', 'tasks.view', 'tasks.create']);
    }

    public function test_it_lists_active_staff_with_name_and_id(): void
    {
        User::factory()->create(['name' => 'María Auxiliar', 'account_type' => 'staff', 'status' => 'active']);
        User::factory()->create(['name' => 'María Inactiva', 'account_type' => 'staff', 'status' => 'inactive']);

        // A portal account can never hold internal work; it needs a client.
        $client = Client::factory()->create();
        User::factory()->create([
            'name' => 'María Portal',
            'account_type' => 'client',
            'client_id' => $client->id,
            'status' => 'active',
        ]);

        $tool = app(TeamDirectoryTool::class);
        $result = $tool->execute($tool->validate(['query' => 'María']), $this->staff);

        $names = array_column($result['people'], 'name');
        $this->assertContains('María Auxiliar', $names);
        $this->assertNotContains('María Portal', $names, 'A portal account can never hold internal work.');
        $this->assertNotContains('María Inactiva', $names, 'A deactivated person cannot pick up the task.');

        // Every entry carries the id the write needs.
        foreach ($result['people'] as $person) {
            $this->assertArrayHasKey('id', $person);
        }
    }

    public function test_it_refuses_a_caller_without_tasks_view(): void
    {
        $outsider = User::factory()->create(['account_type' => 'staff']);

        $tool = app(TeamDirectoryTool::class);

        $this->expectException(AiToolRejected::class);

        $tool->execute($tool->validate([]), $outsider);
    }

    public function test_the_tool_is_registered_and_read_only(): void
    {
        $tool = app(AiToolRegistry::class)->get('team_directory');

        $this->assertFalse($tool->isWrite());
    }
}
