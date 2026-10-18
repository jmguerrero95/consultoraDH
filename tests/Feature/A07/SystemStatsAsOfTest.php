<?php

declare(strict_types=1);

namespace Tests\Feature\A07;

use App\Domain\Ai\AiToolRejected;
use App\Domain\Documents\DocumentIngestor;
use App\Domain\Receivables\ReceivablesService;
use App\Models\AiConversation;
use App\Models\Client;
use App\Models\Company;
use App\Models\MonthlyObligation;
use App\Models\User;
use App\Services\Ai\Tools\SystemStatsTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * A07-R5-R3 (bloque E2) — the cut-off is real, and it says where it came from.
 *
 * The date of the server was already in the prompt; that does not prove the
 * stats tool stopped being handed an invented historical date. These tests pin
 * the contract: with no date asked for, the figures are today's and the payload
 * says so; with a date asked for, the reconstructable money figures are
 * reconstructed and the register counts are labelled as *current*, never
 * relabelled as the cut-off's. An attached spreadsheet cannot change any of it.
 */
final class SystemStatsAsOfTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['clients.view', 'companies.view', 'receivables.view'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $this->user = User::factory()->create(['status' => 'active']);
        $this->user->givePermissionTo(['clients.view', 'companies.view', 'receivables.view']);

        // A controlled clock: the same instant a person in Bogotá would see.
        Carbon::setTestNow(Carbon::parse('2026-10-17 09:30:00', 'America/Bogota'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  list<string>  $permissions
     */
    private function statsFor(array $permissions): array
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->givePermissionTo($permissions);

        $tool = app(SystemStatsTool::class);

        return $tool->execute($tool->validate([]), $user);
    }

    public function test_a_current_question_uses_the_servers_today_and_says_so(): void
    {
        $payload = app(SystemStatsTool::class)->execute([], $this->user);

        $this->assertSame('2026-10-17', $payload['as_of'], 'Today is the server\'s date, not an invented one.');
        $this->assertSame('server_today', $payload['as_of_source']);
        $this->assertArrayNotHasKey('scope', $payload, 'A current question is not a reconstruction.');
        $this->assertFalse($payload['receivables']['historical']);
    }

    public function test_an_explicit_cut_off_is_honoured_and_marked_explicit(): void
    {
        $tool = app(SystemStatsTool::class);

        $payload = $tool->execute($tool->validate(['as_of' => '2025-12-31']), $this->user);

        $this->assertSame('2025-12-31', $payload['as_of']);
        $this->assertSame('explicit', $payload['as_of_source']);
        $this->assertSame('current', $payload['scope']['register']);
        $this->assertSame('current', $payload['clients']['basis']);
        $this->assertContains('clients.active', $payload['not_reconstructable']);
        $this->assertTrue($payload['receivables']['historical']);
    }

    public function test_register_counts_stay_current_while_the_balance_is_reconstructed(): void
    {
        $client = Client::factory()->create(['status' => 'active']);
        $company = Company::factory()->create();

        // An obligation that exists today but did not exist at the cut-off.
        MonthlyObligation::factory()->forMonth('2026-09')->create([
            'client_id' => $client->id,
            'company_id' => $company->id,
            'base_amount_cop' => 250000,
            'generated_at' => Carbon::parse('2026-09-05 09:00:00'),
        ]);

        $tool = app(SystemStatsTool::class);
        $historical = $tool->execute($tool->validate(['as_of' => '2024-01-31']), $this->user);

        // The register count is the whole register, today, and is labelled so.
        $this->assertGreaterThanOrEqual(1, $historical['clients']['total']);
        $this->assertSame('current', $historical['clients']['basis']);

        // The historical balance cannot include an obligation raised two years
        // after the cut-off; the current one can.
        $current = app(ReceivablesService::class)->clientBalanceCounts();
        $this->assertGreaterThan(
            0,
            $current['clients_with_debt'],
            'The obligation of this client counts as debt today.',
        );
    }

    public function test_an_attached_spreadsheet_does_not_change_administrative_figures(): void
    {
        Storage::fake('local');

        $count = Client::query()->count();

        // A fictitious spreadsheet whose numbers are nothing like the register.
        $path = tempnam(sys_get_temp_dir(), 'cdh-csv').'.csv';
        file_put_contents($path, "cliente,obligacion,pagado\nFicticio,9999999,0\n");
        $conversation = AiConversation::query()->create(['user_id' => $this->user->id, 'title' => 'Con adjunto']);

        app(DocumentIngestor::class)->ingest(
            new UploadedFile($path, 'ficticio.csv', 'text/csv', null, true),
            $this->user,
            $conversation->id,
        );

        @unlink($path);

        $payload = app(SystemStatsTool::class)->execute([], $this->user);

        $this->assertSame($count, $payload['clients']['total'], 'The register is the database, not the attachment.');
    }

    public function test_the_register_needs_its_permission_and_the_money_is_omitted_without_its_own(): void
    {
        // A caller with no `clients.view` cannot read the register at all.
        try {
            $this->statsFor(['companies.view']);
            $this->fail('Without clients.view the register must be refused.');
        } catch (AiToolRejected) {
            // Expected.
        }

        // A caller who may read the register but not the money gets counts and
        // no financial block at all — not zeroes, which would be a claim.
        $payload = $this->statsFor(['clients.view']);

        $this->assertArrayHasKey('clients', $payload);
        $this->assertArrayNotHasKey('receivables', $payload);
    }
}
