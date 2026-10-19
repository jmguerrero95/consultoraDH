<?php

declare(strict_types=1);

namespace Tests\Feature\A07;

use App\Models\User;
use App\Services\Ai\Tools\CreateArtifactTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Stability/UX block — a generated file has a legible, safe name.
 *
 * The reported defect: "Guía básica de seguridad informática para pequeñas
 * empresas.pdf" came out with the accented letters and the ñ replaced by
 * underscores, because the sanitiser kept only `[a-zA-Z0-9_-. ]`. A download the
 * person cannot recognise is not a deliverable. These assertions pin the name
 * that survives: accents kept, one extension, no traversal, bounded length.
 */
final class ArtifactFilenameTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'artifacts.create', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'ai.use', 'guard_name' => 'web']);

        $this->user = User::factory()->create();
        $this->user->givePermissionTo(['artifacts.create', 'ai.use']);

        Storage::fake('local');
    }

    private function validatedName(string $filename, string $format = 'pdf'): string
    {
        $tool = app(CreateArtifactTool::class);

        return $tool->validate([
            'format' => $format,
            'filename' => $filename,
            'content' => 'Contenido de prueba.',
        ])['filename'];
    }

    public function test_accents_and_enie_are_preserved(): void
    {
        $this->assertSame(
            'Guía básica de seguridad informática para pequeñas empresas.pdf',
            $this->validatedName('Guía básica de seguridad informática para pequeñas empresas'),
        );
    }

    public function test_the_extension_is_added_once(): void
    {
        $this->assertSame('informe.pdf', $this->validatedName('informe'));
        // A model that already included the extension does not produce a double.
        $this->assertSame('informe.pdf', $this->validatedName('informe.pdf'));
        $this->assertSame('cartera.xlsx', $this->validatedName('cartera.xlsx', 'xlsx'));
    }

    public function test_path_separators_cannot_traverse(): void
    {
        $name = $this->validatedName('../../etc/passwd');

        $this->assertStringNotContainsString('/', $name);
        $this->assertStringNotContainsString('\\', $name);
        $this->assertStringNotContainsString('..', $name);
        $this->assertStringEndsWith('.pdf', $name);
    }

    public function test_control_characters_are_removed(): void
    {
        $name = $this->validatedName("infor\x00me\x1Ffinal");

        $this->assertStringNotContainsString("\x00", $name);
        $this->assertStringNotContainsString("\x1F", $name);
        $this->assertStringContainsString('infor', $name);
    }

    public function test_a_long_name_is_bounded_with_one_extension(): void
    {
        $name = $this->validatedName(str_repeat('muy largo ', 40));

        $this->assertLessThanOrEqual(154, mb_strlen($name));
        $this->assertStringEndsWith('.pdf', $name);
        $this->assertSame(1, substr_count($name, '.pdf'));
    }

    public function test_an_empty_name_falls_back_to_a_readable_default(): void
    {
        $this->assertSame('documento.docx', $this->validatedName('   ...   ', 'docx'));
    }

    public function test_the_artifact_stores_the_human_name_for_download(): void
    {
        $tool = app(CreateArtifactTool::class);
        $arguments = $tool->validate([
            'format' => 'xlsx',
            'filename' => 'Cartera pendiente de La Peñita',
            'content' => json_encode([['Cliente', 'Saldo'], ['Uno', 100]]),
        ]);

        $result = $tool->execute($arguments, $this->user);

        $this->assertSame('Cartera pendiente de La Peñita.xlsx', $result['filename']);
    }
}
