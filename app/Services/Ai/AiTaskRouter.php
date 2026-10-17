<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Domain\Ai\AiToolDefinition;

/**
 * Decides which tools a turn is allowed to offer, from what was actually asked.
 *
 * ## Why not always send every tool
 *
 * Sending the full administrative catalogue on every question has two costs. The
 * obvious one is size: eighteen tool schemas are a large prompt, and a free model
 * given them will spend its answer budget deciding none of them apply. The
 * subtler one is routing: a model that is told it has tools tends to reach for
 * one, and a question like "explícame en dos frases qué es una EPS" has no tool
 * that can help it. The general answer is better *and* cheaper without them.
 *
 * ## Why the decision is conservative
 *
 * The failure that matters here is not "sent a tool and did not use it" — that
 * costs tokens. It is "asked about the client's portfolio and was answered from
 * the model's imagination", which is a wrong answer with no signal that it is
 * wrong. So the router defaults to *keeping* capability: a question it cannot
 * confidently place is treated as a system question, never as general chat. Only
 * a clear general-marking ("qué es", "redacta", "traduce", a greeting) or the
 * complete absence of both a system subject and general marking turns tools off.
 *
 * ## Not a language model
 *
 * This is a keyword router, and deliberately so: it runs on every turn, must be
 * deterministic, must cost nothing, and must be the same in a test as in
 * production. It is not asked to understand the question, only to avoid
 * withholding a capability the question might need.
 */
final class AiTaskRouter
{
    public const TASK_GENERAL = 'general';

    public const TASK_DATA = 'data';

    public const TASK_DOCUMENT = 'document';

    /**
     * Tool names grouped by the subject that needs them.
     *
     * @var array<string, list<string>>
     */
    private const GROUPS = [
        'clients' => ['client_search', 'client_summary', 'client_account'],
        'receivables' => ['receivables_summary', 'client_account'],
        'reports' => ['report_preview', 'create_report'],
        'tasks' => ['operational_tasks', 'create_operational_task'],
        'support' => ['support_summary'],
        'documents' => ['document_search', 'document_read'],
        'automations' => ['automation_vocabulary', 'create_automation_rule', 'activate_automation_rule', 'deactivate_automation_rule'],
        'web' => ['web_search', 'web_fetch'],
        'artifacts' => ['create_artifact'],
    ];

    /**
     * Subject terms, in the order the groups are declared. Matching any of these
     * makes the turn a system turn.
     *
     * @var array<string, list<string>>
     */
    private const SUBJECT_TERMS = [
        'clients' => [
            'cliente', 'clientes', 'clienta', 'clientas',
            'empresa', 'empresas', 'relacion', 'relaciones', 'afiliacion', 'afiliaciones',
            'novedad', 'novedades', 'usuario', 'usuarios', 'sede', 'sedes',
            'contrato', 'contratos', 'cotizacion', 'cotizaciones', 'portafolio',
        ],
        'receivables' => ['cartera', 'saldo', 'saldos', 'recaudo', 'recaudos', 'cobro', 'cobros', 'cobrar', 'deuda', 'deudas', 'factura', 'facturas', 'facturacion', 'pendiente de pago', 'por cobrar'],
        'reports' => ['reporte', 'reportes', 'informe del sistema', 'informes del sistema'],
        'tasks' => ['tarea', 'tareas', 'task'],
        'support' => ['soporte', 'ticket', 'tickets', 'conversacion', 'conversaciones'],
        'documents' => ['documento', 'documentos', 'archivo', 'archivos', 'adjunto', 'adjuntos', 'planilla', 'planillas', 'xlsx', 'csv'],
        'automations' => ['automatizacion', 'automatizaciones', 'regla', 'reglas', 'disparador', 'disparadores', 'automatizar'],
        'web' => ['busca en internet', 'buscar en internet', 'en la web', 'en internet', 'pagina web', 'sitio web'],
        'artifacts' => ['artefacto', 'artefactos', 'descargable', 'en word', 'docx', 'generar un informe', 'generar informe', 'crea un informe', 'crear un informe'],
    ];

    /**
     * Terms that mark a request as general knowledge, writing or conversation.
     *
     * @var list<string>
     */
    private const GENERAL_TERMS = [
        'que es', 'qué es', 'que significa', 'qué significa', 'explica', 'explícame', 'explicame', 'definicion', 'definición', 'define',
        'redacta', 'redactar', 'escribe', 'escribir', 'traduce', 'traducir', 'traduccion', 'traducción',
        'resume este texto', 'resumir este texto', 'resume el texto', 'corrige este texto', 'mejora este texto',
        'organiza', 'organizar', 'planifica una actividad', 'planea una actividad',
        'carta', 'correo', 'email', 'ensayo', 'poema', 'guion', 'cuento', 'discurso',
        'curriculum', 'currículum', ' cv', 'hoja de vida',
        'como funciona', 'cómo funciona', 'diferencia entre', 'ventajas y desventajas',
        'que opinas', 'qué opinas', 'cuentame sobre', 'cuéntame sobre', 'explica en',
    ];

    /**
     * Greetings and meta questions that clearly want no system data.
     *
     * @var list<string>
     */
    private const GREETING_TERMS = [
        'hola', 'buenos dias', 'buenos días', 'buenas tardes', 'buenas noches', 'gracias', 'quien eres', 'quién eres',
        'que puedes hacer', 'qué puedes hacer', 'en que me puedes ayudar', 'en qué me puedes ayudar', 'como estas', 'cómo estás',
    ];

    public function __construct(private readonly AiToolRegistry $registry) {}

    /**
     * @return array{task: string, tools: list<AiToolDefinition>}
     */
    public function route(string $question, bool $hasDocumentContext): array
    {
        $haystack = $this->normalise($question);
        $groups = $this->matchedGroups($haystack);

        // Document context is authoritative: the person attached a file and is
        // asking about it, whatever the words also happen to match.
        if ($hasDocumentContext) {
            $groups[] = 'documents';

            return [
                'task' => self::TASK_DOCUMENT,
                'tools' => $this->definitionsFor(array_values(array_unique($groups))),
            ];
        }

        if ($groups !== []) {
            return [
                'task' => self::TASK_DATA,
                'tools' => $this->definitionsFor($groups),
            ];
        }

        if ($this->looksGeneral($haystack)) {
            return ['task' => self::TASK_GENERAL, 'tools' => []];
        }

        // Nothing matched either way. Keeping the full catalogue is the safe
        // choice: answering a system question from general knowledge is the one
        // failure this router exists to prevent.
        return ['task' => self::TASK_DATA, 'tools' => $this->registry->definitions()];
    }

    /**
     * @return list<string>
     */
    private function matchedGroups(string $haystack): array
    {
        $matched = [];

        foreach (self::SUBJECT_TERMS as $group => $terms) {
            foreach ($terms as $term) {
                if (str_contains($haystack, $this->normalise($term))) {
                    $matched[] = $group;

                    break;
                }
            }
        }

        return $matched;
    }

    private function looksGeneral(string $haystack): bool
    {
        foreach (self::GENERAL_TERMS as $term) {
            if (str_contains($haystack, $this->normalise($term))) {
                return true;
            }
        }

        foreach (self::GREETING_TERMS as $term) {
            if (str_contains($haystack, $this->normalise($term))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The definitions for the matched groups, in the registry's own order.
     *
     * @param  list<string>  $groups
     * @return list<AiToolDefinition>
     */
    private function definitionsFor(array $groups): array
    {
        $wanted = [];

        foreach ($groups as $group) {
            foreach (self::GROUPS[$group] ?? [] as $name) {
                $wanted[$name] = true;
            }
        }

        if ($wanted === []) {
            return $this->registry->definitions();
        }

        $definitions = [];

        foreach ($this->registry->definitions() as $definition) {
            if (isset($wanted[$definition->name])) {
                $definitions[] = $definition;
            }
        }

        // A group naming a tool the registry does not have must not silently
        // produce an empty catalogue: fall back to everything rather than
        // answering a system question with no tools at all.
        return $definitions === [] ? $this->registry->definitions() : $definitions;
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
