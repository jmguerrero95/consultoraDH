<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Domain\Ai\AiTool;
use App\Domain\Ai\AiToolDefinition;

/**
 * A semantic description of everything the assistant can do.
 *
 * ## Why this exists
 *
 * A07-R3 offered the model a *subset* of the registry chosen by
 * {@see AiTaskRouter}'s keyword tables. That made Laravel, not the model, the
 * authority on which capability a request could use: a question the router had
 * not anticipated arrived with no tool that could answer it, and the model could
 * only say so.
 *
 * This catalogue makes the whole registry *discoverable*. It does not execute
 * anything and it does not widen any permission; it is a map the model may read
 * so it can decide which tool it needs. Execution still goes through the closed
 * registry, with the tool re-checking the caller's permission at call time.
 *
 * ## Two audiences, two shapes
 *
 * `summaries()` is the compact form a model reads to decide — a sentence each,
 * no JSON-schema noise — so a small free model is not drowned by twenty large
 * definitions. `definitionsFor()` is the exact wire shape, used to add a tool
 * to the offered set *after* the model has discovered it.
 *
 * ## Privacy is described, not decided, here
 *
 * `private_data` marks a capability that answers from Consultora DH's own
 * records. It is metadata for the model and for the turn's provider-eligibility
 * check, never a permission: the tool itself still refuses a caller who lacks
 * the permission the equivalent screen requires.
 */
final class AiCapabilityCatalog
{
    /**
     * The discovery tool's name. It is handled by the assistant service rather
     * than the registry, so it is never executed as business logic: its only
     * output is this catalogue.
     */
    public const DISCOVERY_TOOL = 'discover_capabilities';

    /**
     * Tools offered on every turn, so a general request never has to discover
     * them merely to begin: research, reading a source, and producing a file are
     * the three things the product promises are always available.
     */
    public const CORE = ['web_search', 'web_fetch', 'create_artifact'];

    /**
     * Hand-written semantics for each registered tool.
     *
     * `purpose` is what it does, `when` is the situation that calls for it,
     * `private` says it answers from our records, `internet` says it leaves the
     * building, and `keywords` are the terms a model is likely to use when it
     * needs it.
     *
     * @var array<string, array{purpose: string, when: string, private: bool, internet: bool, keywords: list<string>}>
     */
    private const META = [
        'client_search' => [
            'purpose' => 'Buscar clientes por nombre, documento o empresa y devolver coincidencias.',
            'when' => 'Cuando haya que localizar a un cliente o saber quién cumple un criterio.',
            'private' => true, 'internet' => false,
            'keywords' => ['cliente', 'clientes', 'buscar cliente', 'documento', 'cedula', 'nit', 'afiliado', 'persona'],
        ],
        'client_summary' => [
            'purpose' => 'Resumen de un cliente: datos, empresas, relaciones y estado.',
            'when' => 'Cuando pidan el perfil o el resumen de un cliente concreto.',
            'private' => true, 'internet' => false,
            'keywords' => ['resumen de cliente', 'perfil', 'ficha', 'datos del cliente', 'quien es', 'relaciones'],
        ],
        'client_account' => [
            'purpose' => 'Cuenta de cartera de un cliente: obligaciones, pagos y saldos.',
            'when' => 'Cuando pidan el saldo, los pagos o las obligaciones de un cliente.',
            'private' => true, 'internet' => false,
            'keywords' => ['saldo de cliente', 'cuenta', 'pagos', 'obligaciones', 'deuda de un cliente', 'abonos'],
        ],
        'receivables_summary' => [
            'purpose' => 'Resumen de cartera: totales, vencidos y desglose por empresa.',
            'when' => 'Cuando pidan cifras generales de cartera, recaudo o mora.',
            'private' => true, 'internet' => false,
            'keywords' => ['cartera', 'recaudo', 'mora', 'vencido', 'por cobrar', 'saldos', 'facturacion'],
        ],
        'system_stats' => [
            'purpose' => 'Cifras agregadas: número de clientes, empresas, con deuda y con pagos parciales.',
            'when' => 'Cuando pidan conteos, indicadores o estadísticas del sistema.',
            'private' => true, 'internet' => false,
            'keywords' => ['cuantos', 'estadisticas', 'indicadores', 'totales', 'numero de clientes', 'metricas', 'cifras'],
        ],
        'operational_tasks' => [
            'purpose' => 'Listar las tareas operativas registradas y su estado.',
            'when' => 'Cuando pregunten por tareas, pendientes o su seguimiento.',
            'private' => true, 'internet' => false,
            'keywords' => ['tareas', 'pendientes', 'asignaciones', 'seguimiento', 'actividades'],
        ],
        'create_operational_task' => [
            'purpose' => 'Proponer la creación de una tarea operativa.',
            'when' => 'Cuando pidan crear o asignar una tarea.',
            'private' => true, 'internet' => false,
            'keywords' => ['crear tarea', 'asignar tarea', 'nueva tarea', 'agendar'],
        ],
        'report_preview' => [
            'purpose' => 'Previsualizar un informe del sistema sin generarlo.',
            'when' => 'Cuando pidan ver un informe antes de producirlo.',
            'private' => true, 'internet' => false,
            'keywords' => ['informe', 'reporte', 'previsualizar', 'resumen ejecutivo'],
        ],
        'create_report' => [
            'purpose' => 'Proponer la generación de un informe del sistema.',
            'when' => 'Cuando pidan crear un informe del sistema.',
            'private' => true, 'internet' => false,
            'keywords' => ['crear informe', 'generar reporte', 'informe del sistema'],
        ],
        'support_summary' => [
            'purpose' => 'Resumen del soporte: conversaciones y tickets.',
            'when' => 'Cuando pregunten por soporte, tickets o PQRS.',
            'private' => true, 'internet' => false,
            'keywords' => ['soporte', 'ticket', 'pqr', 'conversaciones', 'atencion'],
        ],
        'automation_vocabulary' => [
            'purpose' => 'Vocabulario, eventos y acciones disponibles para automatizaciones.',
            'when' => 'Antes de crear o explicar una automatización.',
            'private' => false, 'internet' => false,
            'keywords' => ['automatizacion', 'regla', 'disparador', 'evento', 'accion'],
        ],
        'create_automation_rule' => [
            'purpose' => 'Proponer una regla de automatización.',
            'when' => 'Cuando pidan automatizar algo.',
            'private' => true, 'internet' => false,
            'keywords' => ['crear automatizacion', 'nueva regla', 'automatizar'],
        ],
        'activate_automation_rule' => [
            'purpose' => 'Proponer activar una regla de automatización.',
            'when' => 'Cuando pidan activar una automatización.',
            'private' => true, 'internet' => false,
            'keywords' => ['activar regla', 'encender automatizacion'],
        ],
        'deactivate_automation_rule' => [
            'purpose' => 'Proponer desactivar una regla de automatización.',
            'when' => 'Cuando pidan desactivar una automatización.',
            'private' => true, 'internet' => false,
            'keywords' => ['desactivar regla', 'apagar automatizacion'],
        ],
        'document_search' => [
            'purpose' => 'Buscar dentro de los documentos adjuntos a la conversación.',
            'when' => 'Cuando pregunten por el contenido de un archivo que subieron.',
            'private' => true, 'internet' => false,
            'keywords' => ['documento', 'adjunto', 'archivo', 'excel', 'pdf', 'buscar en el archivo', 'hoja'],
        ],
        'document_read' => [
            'purpose' => 'Leer el contenido de un documento adjunto.',
            'when' => 'Cuando necesiten el texto de un adjunto que ya localizaron.',
            'private' => true, 'internet' => false,
            'keywords' => ['leer documento', 'contenido del archivo', 'texto del adjunto'],
        ],
        'web_search' => [
            'purpose' => 'Buscar en internet información pública y actual.',
            'when' => 'Cuando la respuesta dependa de datos públicos, actuales o que no están en el sistema.',
            'private' => false, 'internet' => true,
            'keywords' => ['internet', 'buscar', 'noticias', 'actualidad', 'investigar', 'fuentes', 'web'],
        ],
        'web_fetch' => [
            'purpose' => 'Abrir una URL de los resultados y leer su contenido completo.',
            'when' => 'Cuando una fuente necesite más detalle que el fragmento de la búsqueda.',
            'private' => false, 'internet' => true,
            'keywords' => ['abrir pagina', 'leer url', 'fuente', 'mas detalle', 'articulo'],
        ],
        'weather_current' => [
            'purpose' => 'Clima actual y pronóstico por lugar.',
            'when' => 'Cuando pregunten por temperatura, lluvia, humedad o clima.',
            'private' => false, 'internet' => true,
            'keywords' => ['clima', 'temperatura', 'lluvia', 'pronostico', 'humedad', 'tiempo'],
        ],
        'create_artifact' => [
            'purpose' => 'Generar un archivo privado (PDF, DOCX, XLSX, CSV, MD, TXT) y devolver su enlace de descarga.',
            'when' => 'Cuando pidan un documento, informe, hoja de cálculo o exportación.',
            'private' => false, 'internet' => false,
            'keywords' => ['pdf', 'word', 'docx', 'excel', 'xlsx', 'csv', 'documento', 'informe', 'archivo', 'descargar', 'exportar'],
        ],
    ];

    public function __construct(private readonly AiToolRegistry $registry) {}

    /**
     * The tool definition offered so the model can ask what else exists.
     */
    public function discoveryDefinition(): AiToolDefinition
    {
        return new AiToolDefinition(
            self::DISCOVERY_TOOL,
            'Devuelve el catálogo de capacidades disponibles (herramientas) con lo que hace cada una y cuándo usarla. '
                .'Úsala cuando la tarea necesite algo que no esté entre las herramientas ya disponibles: encontrar clientes, '
                .'cartera, estadísticas, tareas, informes, soporte, automatizaciones o documentos. Tras llamarla podrás '
                .'invocar directamente la herramienta adecuada.',
            [
                'type' => 'object',
                'properties' => [
                    'query' => [
                        'type' => 'string',
                        'description' => 'Qué necesitas hacer, en texto libre (por ejemplo: "saldo de cartera por empresa"). '
                            .'Si lo omites, se devuelve el catálogo completo.',
                    ],
                ],
                'required' => [],
            ],
        );
    }

    /**
     * The catalogue in the compact form the model reads.
     *
     * @return list<array<string, mixed>>
     */
    public function summaries(?string $query = null): array
    {
        $entries = $this->entries();
        $query = trim((string) $query);

        if ($query === '') {
            return $entries;
        }

        $tokens = $this->tokens($query);
        $ranked = [];

        foreach ($entries as $entry) {
            $haystack = $this->normalise(implode(' ', [
                (string) $entry['name'],
                (string) $entry['purpose'],
                (string) $entry['when'],
                implode(' ', $entry['keywords']),
            ]));

            $score = 0;

            foreach ($tokens as $token) {
                if (mb_strlen($token) >= 3 && str_contains($haystack, $token)) {
                    $score++;
                }
            }

            if ($score > 0) {
                $entry['score'] = $score;
                $ranked[] = $entry;
            }
        }

        if ($ranked === []) {
            // A query the classifier does not recognise must not hide the
            // catalogue: returning everything is how the model still discovers
            // the right tool.
            return $entries;
        }

        usort($ranked, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_map(static function (array $entry): array {
            unset($entry['score']);

            return $entry;
        }, $ranked);
    }

    /**
     * The exact wire definitions for the named tools, in registry order.
     *
     * @param  list<string>  $names
     * @return list<AiToolDefinition>
     */
    public function definitionsFor(array $names): array
    {
        $wanted = array_flip($names);
        $definitions = [];

        foreach ($this->registry->definitions() as $definition) {
            if (isset($wanted[$definition->name])) {
                $definitions[] = $definition;
            }
        }

        return $definitions;
    }

    /**
     * Whether the named tool answers from Consultora DH's own records.
     */
    public function isPrivateTool(string $name): bool
    {
        return (bool) (self::META[$name]['private'] ?? false);
    }

    /**
     * The names of the always-offered tools that actually exist.
     *
     * @return list<string>
     */
    public function coreNames(): array
    {
        return array_values(array_filter(
            self::CORE,
            fn (string $name): bool => $this->registry->has($name),
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function entries(): array
    {
        $entries = [];

        foreach ($this->registry->all() as $name => $tool) {
            $meta = self::META[$name] ?? [
                'purpose' => $tool->description(),
                'when' => 'Cuando la tarea lo requiera.',
                'private' => false,
                'internet' => false,
                'keywords' => [],
            ];

            $entries[] = [
                'name' => $name,
                'purpose' => $meta['purpose'],
                'when' => $meta['when'],
                'mode' => $tool->isWrite() ? 'write' : 'read',
                'private_data' => $meta['private'],
                'needs_internet' => $meta['internet'],
                'arguments' => $this->argumentNames($tool),
                'keywords' => $meta['keywords'],
            ];
        }

        return $entries;
    }

    /**
     * @return list<string>
     */
    private function argumentNames(AiTool $tool): array
    {
        $schema = $tool->parameters();
        $properties = $schema['properties'] ?? [];

        return is_array($properties) ? array_keys($properties) : [];
    }

    /**
     * @return list<string>
     */
    private function tokens(string $text): array
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $this->normalise($text)) ?: [];

        return array_values(array_filter($parts, static fn (string $token): bool => $token !== ''));
    }

    private function normalise(string $text): string
    {
        $lower = mb_strtolower($text);

        return strtr($lower, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
            'ä' => 'a', 'ë' => 'e', 'ï' => 'i', 'ö' => 'o', 'ü' => 'u',
            'ñ' => 'n', 'ç' => 'c',
        ]);
    }
}
