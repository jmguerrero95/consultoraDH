<?php

declare(strict_types=1);

namespace Tests\Feature\A07;

use App\Models\AiArtifact;
use App\Models\AiConversation;
use App\Models\User;
use App\Services\Ai\AiToolRegistry;
use App\Services\Ai\AiTurnContext;
use App\Services\Ai\Tools\CreateArtifactTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * A07-R5-R2 (bloque C2/C3, D5) — the files are real, and one request is one file.
 *
 * The reported defects were a Word document whose Markdown tables vanished (the
 * writer skipped every `|` line) leaving an empty section and a literal `---`,
 * a title printed twice, and three copies of the same Excel file from one turn.
 */
final class ArtifactQualityTest extends TestCase
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

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function create(array $arguments): array
    {
        $tool = app(CreateArtifactTool::class);

        return $tool->execute($tool->validate($arguments), $this->user);
    }

    /**
     * The plain text of a DOCX, as Word will show it.
     */
    private function docxText(string $bytes): string
    {
        $temp = tempnam(sys_get_temp_dir(), 'docx_').'.docx';
        file_put_contents($temp, $bytes);

        $zip = new \ZipArchive;
        $zip->open($temp);
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        @unlink($temp);

        preg_match_all('/<w:t[^>]*>(.*?)<\/w:t>/s', $xml, $matches);

        return implode("\n", array_map(
            static fn (string $t): string => html_entity_decode($t, ENT_QUOTES | ENT_XML1, 'UTF-8'),
            $matches[1],
        ));
    }

    private function docxXml(string $bytes): string
    {
        $temp = tempnam(sys_get_temp_dir(), 'docx_').'.docx';
        file_put_contents($temp, $bytes);

        $zip = new \ZipArchive;
        $zip->open($temp);
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        @unlink($temp);

        return $xml;
    }

    public function test_a_markdown_table_becomes_a_real_word_table(): void
    {
        $content = <<<'MD'
        # Informe de cartera
        Sección de gestión de deudas.

        | Categoría | Obligación | Pagado | Saldo |
        | --- | --- | --- | --- |
        | A | 300000 | 100000 | 200000 |
        | B | 250000 | 250000 | 0 |

        ---

        Fin del informe.
        MD;

        $result = $this->create([
            'format' => 'docx',
            'filename' => 'informe-cartera',
            'title' => 'Informe de cartera',
            'content' => $content,
        ]);

        $artifact = AiArtifact::query()->findOrFail($result['artifact_id']);
        $bytes = Storage::disk('local')->get($artifact->storage_path);
        $xml = $this->docxXml($bytes);
        $text = $this->docxText($bytes);

        // A real Word table, not a paragraph of pipes.
        $this->assertStringContainsString('<w:tbl>', $xml, 'The Markdown table must become a real Word table.');

        // Its cells carry the data.
        $this->assertStringContainsString('Categoría', $text);
        $this->assertStringContainsString('Obligación', $text);
        $this->assertStringContainsString('300000', $text);
        $this->assertStringContainsString('200000', $text);

        // No raw Markdown leaked into the document.
        $this->assertStringNotContainsString('|', $text, 'No pipe characters may remain in the text.');
        $this->assertStringNotContainsString('---', $text, 'A rule is format, not the literal text "---".');

        // The title is printed once, not twice.
        $this->assertSame(1, substr_count($text, 'Informe de cartera'));
    }

    public function test_the_same_request_in_one_turn_produces_one_file(): void
    {
        $conversation = AiConversation::query()->create(['user_id' => $this->user->id, 'title' => 'Cartera']);
        app(AiTurnContext::class)->set($conversation->id, 'turn-1');

        $arguments = [
            'format' => 'xlsx',
            'filename' => 'gestion-de-deudas',
            'title' => 'Gestión de deudas',
            'content' => json_encode([['Categoría', 'Obligación'], ['A', 300000]]),
        ];

        $first = $this->create($arguments);
        $second = $this->create($arguments);

        $this->assertTrue($first['created']);
        $this->assertFalse($second['created']);
        $this->assertTrue($second['deduplicated']);
        $this->assertSame($first['artifact_id'], $second['artifact_id']);
        $this->assertSame(1, AiArtifact::query()->count(), 'A repeated call must not write a second file.');
    }

    public function test_create_artifact_is_registered_and_read_only(): void
    {
        $tool = app(AiToolRegistry::class)->get('create_artifact');

        $this->assertFalse($tool->isWrite(), 'Creating a private file the user asked for is not a business write.');
    }
}
