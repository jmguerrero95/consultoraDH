<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Domain\Ai\AiToolDefinition;

/**
 * Decides what a turn needs: which tools to offer, and whether the answer
 * depends on information that changes over time.
 *
 * ## Why not always send every tool
 *
 * Sending the full administrative catalogue on every question has two costs. The
 * obvious one is size: twenty tool schemas are a large prompt, and a free model
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
 * ## Freshness, not keywords
 *
 * The router does not need the person to say "busca en internet". A request for
 * today's temperature, this week's news or a current regulation needs external,
 * up-to-date information whether or not it names the web. `freshness()` decides
 * that from temporal markers and the subjects that are only ever current
 * (weather, news, rates, reforms), and `web` is then true — the assistant
 * prefetches a real search instead of asking the model whether it would like to
 * search.
 *
 * ## Follow-ups
 *
 * "Sí, hazlo" or "¿y cómo estará mañana?" only makes sense against the previous
 * request. When the question reads as a continuation, the router classifies the
 * *previous* user question as well and unions both: the tools, the web need and
 * the location carry over, so a short follow-up does not fall back to general
 * chat and does not ask the location again.
 *
 * ## Not a language model
 *
 * This is a keyword-and-marker router, and deliberately so: it runs on every
 * turn, must be deterministic, must cost nothing, and must be the same in a test
 * as in production. It is not asked to understand the question, only to avoid
 * withholding a capability the question might need.
 */
final class AiTaskRouter
{
    public const TASK_GENERAL = 'general';

    public const TASK_DATA = 'data';

    public const TASK_DOCUMENT = 'document';

    /**
     * A turn whose answer depends on public, current information and carries no
     * business data by itself.
     */
    public const TASK_WEB = 'web';

    /**
     * Tool names grouped by the subject that needs them.
     *
     * @var array<string, list<string>>
     */
    private const GROUPS = [
        'clients' => ['client_search', 'client_summary', 'client_account'],
        'receivables' => ['receivables_summary', 'client_account'],
        'stats' => ['system_stats'],
        'reports' => ['report_preview', 'create_report'],
        'tasks' => ['operational_tasks', 'create_operational_task'],
        'support' => ['support_summary'],
        'documents' => ['document_search', 'document_read'],
        'automations' => ['automation_vocabulary', 'create_automation_rule', 'activate_automation_rule', 'deactivate_automation_rule'],
        'web' => ['web_search', 'web_fetch', 'weather_current'],
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
        'stats' => [
            'cuantos clientes', 'cuantas empresas', 'estadistica', 'estadisticas', 'metricas', 'indicadores',
            'cuantos hay', 'cuantas hay', 'total de clientes', 'total de empresas',
            'clientes inactivos', 'clientes activos', 'pagaron parcial', 'pago parcial', 'pagos parciales',
            'deben dinero', 'tienen deuda', 'con deuda', 'solo cifras', 'solo numeros', 'solo números',
        ],
        'reports' => ['reporte', 'reportes', 'informe del sistema', 'informes del sistema'],
        'tasks' => ['tarea', 'tareas', 'task'],
        'support' => ['soporte', 'ticket', 'tickets', 'conversacion', 'conversaciones'],
        'documents' => ['documento', 'documentos', 'archivo', 'archivos', 'adjunto', 'adjuntos', 'planilla', 'planillas', 'xlsx', 'csv'],
        'automations' => ['automatizacion', 'automatizaciones', 'regla', 'reglas', 'disparador', 'disparadores', 'automatizar'],
        'web' => [
            'busca en internet', 'buscar en internet', 'busca en la web', 'buscar en la web',
            'en la web', 'en internet', 'pagina web', 'pagina web', 'sitio web',
            'investiga', 'investigar', 'investigacion', 'investigación', 'consulta en linea',
            'busca informacion', 'buscar informacion', 'busca noticias', 'noticias',
            'fuentes', 'dame las fuentes', 'cita las fuentes', 'citar fuentes',
        ],
        'artifacts' => [
            'artefacto', 'artefactos', 'descargable', 'en word', 'docx', 'pdf',
            'generar un informe', 'generar informe', 'crea un informe', 'crear un informe',
            'genera un pdf', 'generar un pdf', 'crea un pdf', 'crear un pdf', 'hazme un pdf',
            'genera un documento', 'generar un documento', 'crea un documento', 'crear un documento',
            'curriculum', 'currículum', 'hoja de vida', 'excel', 'xlsx', 'hoja de calculo', 'hoja de cálculo',
            'genera un excel', 'generar un excel', 'conviertelo a excel', 'conviértelo a excel',
            'conviertelo a pdf', 'conviértelo a pdf', 'genera el archivo',
        ],
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
        'como funciona', 'cómo funciona', 'diferencia entre', 'ventajas y desventajas',
        'que opinas', 'qué opinas', 'cuentame sobre', 'cuéntame sobre', 'explica en',
    ];

    /**
     * The subset of general markers that ask for an explanation, not an action.
     *
     * A07-R5 separates "answer me in prose" from "do something for me". A question
     * in this subset ("qué es", "diferencia entre", "cómo funciona") needs no tool
     * at all, so it can be served by a chat-only model and must not spend a tool
     * round. General markers outside it — "organiza", "redacta", "genera" — may
     * still need a capability the router cannot foresee, so their turns keep the
     * discovery tool and are not silently stripped. The distinction is lexical and
     * free: no second model call decides it.
     *
     * @var list<string>
     */
    private const CONVERSATIONAL_TERMS = [
        'que es', 'qué es', 'que significa', 'qué significa', 'explica', 'explícame', 'explicame',
        'definicion', 'definición', 'define', 'diferencia entre', 'como funciona', 'cómo funciona',
        'ventajas y desventajas', 'que opinas', 'qué opinas', 'cuentame sobre', 'cuéntame sobre',
        'explica en',
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

    /**
     * Markers that an answer must be current rather than remembered.
     *
     * @var list<string>
     */
    private const FRESHNESS_TERMS = [
        'hoy', 'ayer', 'manana', 'mañana', 'esta semana', 'este mes', 'este ano', 'este año',
        'ahora', 'ahorita', 'en este momento', 'actual', 'actualmente', 'hoy en dia', 'hoy en día',
        'reciente', 'recientes', 'ultimamente', 'últimamente', 'ultimas', 'últimas', 'ultimo', 'último',
        'novedades', 'noticias', 'en vivo', 'en tiempo real', 'al dia de hoy', 'al día de hoy',
        'vigente', 'vigentes', 'entra en vigencia', 'entró en vigencia', 'entro en vigencia',
        'reforma', 'reforma laboral', 'reforma pensional', 'reforma tributaria', 'nueva norma',
        'nuevo decreto', 'nueva resolucion', 'nueva resolución', 'sentencia reciente',
        'cotizacion', 'cotización', 'precio actual', 'precios actuales', 'tasa de cambio',
        'trm', 'dolar', 'dólar', 'inflacion', 'inflación', 'ipc', 'salario minimo', 'salario mínimo',
        'uvr', 'indicador', 'pronostico', 'pronóstico', 'temperatura', 'clima', 'humedad', 'lluvia',
        'como estara', 'cómo estará', 'como va a estar', 'que tiempo hara', 'qué tiempo hará',
    ];

    /**
     * Subjects that are only ever current: matching one is fresh by itself.
     *
     * @var list<string>
     */
    private const WEATHER_TERMS = [
        'temperatura', 'humedad', 'clima', 'pronostico del tiempo', 'pronóstico del tiempo',
        'estado del tiempo', 'va a llover', 'llovera', 'lloverá', 'lluvia', 'grados centigrados',
        'grados centígrados', 'que tiempo hace', 'qué tiempo hace', 'como estara el clima',
    ];

    /**
     * A short message that continues the previous request rather than starting one.
     *
     * @var list<string>
     */
    private const FOLLOWUP_TERMS = [
        'si,', 'sí,', 'si, hazlo', 'sí, hazlo', 'hazlo', 'hazlo ya', 'haz eso', 'hazlo por favor',
        'investiga eso', 'investigalo', 'investígalo', 'busca eso', 'consulta eso',
        'continua', 'continúa', 'prosigue', 'procede', 'adelante', 'dale',
        'amplia', 'amplía', 'amplia eso', 'extiende', 'desarrolla eso',
        'dame las fuentes', 'dame fuentes', 'las fuentes', 'muestra las fuentes', 'y las fuentes',
        'compara', 'compáralo', 'comparalo', 'comparalo con', 'compáralo con',
        'genera el pdf', 'genera el documento', 'genera el archivo', 'ahora genera', 'y ahora genera',
        'conviertelo', 'conviértelo', 'pasalo a', 'pásalo a', 'exportalo', 'expórtalo', 'exportalo',
        'lo mismo', 'eso mismo', 'usa el archivo', 'usa eso', 'con eso', 'de nuevo', 'otra vez',
        'y como estara', 'y cómo estará', 'y manana', 'y mañana',
    ];

    /**
     * Words that are never part of a place name, stripped before geocoding.
     *
     * @var list<string>
     */
    private const LOCATION_STOPWORDS = [
        'revisa', 'revisar', 'dime', 'dame', 'cual', 'cuál', 'cuales', 'cuáles', 'que', 'qué', 'como', 'cómo',
        'cuanto', 'cuánto', 'cuanta', 'cuánta', 'es', 'esta', 'está', 'estan', 'están', 'hay', 'hace', 'hara', 'hará',
        'el', 'la', 'los', 'las', 'un', 'una', 'unos', 'unas', 'de', 'del', 'en', 'para', 'por', 'con', 'y', 'o',
        'favor', 'hoy', 'ayer', 'manana', 'mañana', 'ahora', 'actual', 'actualmente', 'dia', 'día',
        'temperatura', 'humedad', 'clima', 'tiempo', 'pronostico', 'pronóstico', 'lluvia', 'llover', 'llovera', 'lloverá',
        'grados', 'centigrados', 'centígrados', 'como', 'estara', 'estará', 'va', 'a', 'estar', 'temperaturas',
        'clima', 'ambiente', 'sensacion', 'sensación', 'termica', 'térmica', 'relativa', 'porcentaje',
    ];

    /**
     * Subjects that make a question a *public* one even when a word in it also
     * belongs to the application's vocabulary.
     *
     * "novedades" is a domain word here (a client's changes) and also the word
     * in "las novedades de la reforma laboral". A question that names the law,
     * the government or the regulation is a public question, and is treated as
     * needing current sources.
     *
     * @var list<string>
     */
    private const PUBLIC_TOPIC_TERMS = [
        'normativa', 'normatividad', 'legislacion', 'legislación', 'ley ', 'leyes', 'decreto', 'decretos',
        'resolucion', 'resolución', 'sentencia', 'jurisprudencia', 'reforma', 'regulacion', 'regulación',
        'reglamento', 'ministerio', 'gobierno', 'congreso', 'superintendencia', 'asamblea',
        'actualidad nacional', 'pais', 'país', 'nacional', 'elecciones', 'presidente',
        'tributaria', 'pensional', 'pensional', 'laboral', 'salud publica', 'salud pública',
        'noticia', 'suceso', 'conflicto', 'economia', 'economía', 'inflacion', 'inflación',
    ];

    /**
     * Phrases that show the question is about *our* data, not the world's.
     *
     * These suppress an automatic web search even when the question also carries
     * a freshness or public-topic marker, because the person is asking about the
     * application and naming a client or the portfolio, and a private name is not
     * a search term.
     *
     * @var list<string>
     */
    private const OUR_DATA_TERMS = [
        'nuestra cartera', 'nuestros clientes', 'nuestras empresas', 'nuestra empresa',
        'del sistema', 'en el sistema', 'en la plataforma', 'en consultora', 'de consultora',
        'tenemos', 'cuantos clientes', 'cuántos clientes', 'cuantas empresas', 'cuántas empresas',
        'nuestro portafolio', 'nuestra afiliacion', 'nuestra afiliación', 'este cliente',
    ];

    public function __construct(private readonly AiToolRegistry $registry) {}

    /**
     * @param  list<string>  $recentUserQuestions  most recent last; used only to
     *                                             resolve a follow-up
     * @return array{task: string, tools: list<AiToolDefinition>, web: bool, weather: bool, search_query: string|null, location: string|null, conversational: bool}
     */
    public function route(string $question, bool $hasDocumentContext, array $recentUserQuestions = []): array
    {
        $haystack = $this->normalise($question);
        $previous = $this->previousQuestion($recentUserQuestions);

        // A follow-up is classified together with the request it continues, so
        // "¿y cómo estará mañana?" keeps the web need and the location of the
        // weather question before it.
        $isFollowUp = $previous !== null && $this->looksFollowUp($haystack, $previous);

        $topics = [$haystack];

        if ($isFollowUp) {
            $topics[] = $this->normalise($previous);
        }

        $groups = [];
        $fresh = false;
        $weather = false;
        $publicTopic = false;
        $aboutOurData = false;

        foreach ($topics as $topic) {
            foreach ($this->matchedGroups($topic) as $group) {
                $groups[] = $group;
            }

            $fresh = $fresh || $this->isFresh($topic);
            $weather = $weather || $this->isWeather($topic);
            $publicTopic = $publicTopic || $this->matchesAny($topic, self::PUBLIC_TOPIC_TERMS);
            $aboutOurData = $aboutOurData || $this->matchesAny($topic, self::OUR_DATA_TERMS);
        }

        $groups = array_values(array_unique($groups));

        $explicitWeb = in_array('web', $groups, true);
        $internalGroups = array_values(array_diff($groups, ['web', 'artifacts']));

        // A weather question, an explicit "busca en internet", a public-topic
        // question such as a reform or a new regulation, and a freshness marker
        // with no internal subject all need current, external information.
        //
        // `$fresh` alone does not: "¿cuántos clientes hay hoy?" is current and
        // internal, and searching the web for the portfolio would be both useless
        // and a leak of whatever the question named.
        $needsWeb = $explicitWeb
            || $publicTopic
            || $weather
            || ($fresh && $internalGroups === []);

        if ($needsWeb) {
            $groups[] = 'web';
            $groups = array_values(array_unique($groups));
        }

        // The server prefetches only when the question is about the world. When
        // it is about our own data the web tools are still offered, so a person
        // who explicitly asks to search still can, but nothing is sent anywhere
        // automatically.
        $prefetch = $needsWeb && ! $aboutOurData;

        // The place to look up. A follow-up such as "¿y mañana?" contains no
        // place of its own — every token it leaves after stopword removal is
        // noise ("tal", from "qué tal") — so it keeps the place named in the
        // request it continues. A follow-up that does name a place ("¿y en
        // Bogotá?") names it after "en" and is used as-is.
        $ownLocation = $this->extractLocation($haystack);
        $previousLocation = $previous !== null ? $this->extractLocation($this->normalise($previous)) : null;

        $location = $ownLocation;

        if ($isFollowUp && $previousLocation !== null && ! str_contains($haystack, ' en ')) {
            $location = $previousLocation;
        }

        if ($location === null && ($weather || $publicTopic)) {
            $location = $previousLocation;
        }

        // The query the prefetch will use. A follow-up is joined to the request
        // it continues, so a search for "¿y cómo estará mañana?" searches for the
        // topic as well as the question.
        $searchQuery = $isFollowUp && $previous !== null
            ? trim($previous.' '.$question)
            : $question;

        // Document context is authoritative for *what the question is about* — the
        // person attached a file and is asking about it. It is not authoritative
        // for *which capabilities the answer needs*: "lee este documento,
        // investiga si sigue vigente y hazme un informe" needs the document, the
        // internet and file generation at once. The A07-R3 router forced
        // `web=false` here, which made that impossible.
        if ($hasDocumentContext) {
            $groups[] = 'documents';
            $groups = array_values(array_unique($groups));

            $webAvailable = $this->registry->has('web_search') || $this->registry->has('weather_current');

            return [
                'task' => self::TASK_DOCUMENT,
                'tools' => $this->definitionsFor($groups),
                'web' => $prefetch && $webAvailable,
                'weather' => $weather && $prefetch && $this->registry->has('weather_current'),
                'search_query' => $searchQuery,
                'location' => $location,
                'conversational' => false,
            ];
        }

        if ($groups !== []) {
            $webAvailable = $this->registry->has('web_search') || $this->registry->has('weather_current');

            return [
                'task' => $this->taskFor($internalGroups, $needsWeb && $webAvailable),
                'tools' => $this->definitionsFor($groups),
                'web' => $prefetch && $webAvailable,
                'weather' => $weather && $prefetch && $this->registry->has('weather_current'),
                'search_query' => $searchQuery,
                'location' => $location,
                'conversational' => false,
            ];
        }

        // A freshness marker with no other subject: still a web turn. "¿Qué
        // noticias hay hoy?" and "¿cuál es el precio del dólar?" have no
        // administrative subject and are exactly what the web is for.
        if ($needsWeb) {
            $webAvailable = $this->registry->has('web_search') || $this->registry->has('weather_current');

            return [
                'task' => self::TASK_WEB,
                'tools' => $this->definitionsFor(['web']),
                'web' => $prefetch && $webAvailable,
                'weather' => $weather && $prefetch && $this->registry->has('weather_current'),
                'search_query' => $searchQuery,
                'location' => $location,
                'conversational' => false,
            ];
        }

        if ($this->looksGeneral($haystack)) {
            return [
                'task' => self::TASK_GENERAL,
                'tools' => [],
                'web' => false,
                'weather' => false,
                'search_query' => null,
                'location' => null,
                // Only a genuinely conversational request (an explanation or a
                // greeting) is served without any tool. A broader general marker
                // ("organiza…") keeps the discovery tool so a capability the
                // router did not foresee is still reachable.
                'conversational' => $this->looksConversational($haystack),
            ];
        }

        // Nothing matched either way. Keeping the full catalogue is the safe
        // choice: answering a system question from general knowledge is the one
        // failure this router exists to prevent.
        return [
            'task' => self::TASK_DATA,
            'tools' => $this->registry->definitions(),
            'web' => false,
            'weather' => false,
            'search_query' => null,
            'location' => null,
            'conversational' => false,
        ];
    }

    /**
     * Web-only turns carry no business data, so they are `web`; a turn that also
     * touches the system is `data` and is treated as carrying business data.
     *
     * @param  list<string>  $internalGroups
     */
    private function taskFor(array $internalGroups, bool $needsWeb): string
    {
        if ($internalGroups === [] && $needsWeb) {
            return self::TASK_WEB;
        }

        return self::TASK_DATA;
    }

    /**
     * Whether the text contains any of the given (already normalised) phrases.
     *
     * @param  list<string>  $terms
     */
    private function matchesAny(string $haystack, array $terms): bool
    {
        foreach ($terms as $term) {
            if ($this->matchesTerm($haystack, $term)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a whole word or phrase appears, never inside another word.
     *
     * The keyword tables used raw `str_contains`, so a stem matched any longer
     * word that merely ended with it: "relacional" contains "relacion" and turned
     * a theoretical question about databases into an administrative *clients*
     * turn, which is the A07-R5 T01 defect. "documental" contained "documento"
     * and "cartagena" contained "carta" for the same reason.
     *
     * The match is now anchored to word edges, so "relacional" no longer matches
     * "relacion" while "relacion" still matches "relaciones".
     *
     * Spanish inflection is handled generically rather than by listing every
     * surface form: a term may be followed by a plural or adverbial ending
     * (`s`, `es`, `mente`). That turns "cliente" into "clientes", "novedad" into
     * "novedades" and "actual" into "actualmente" without re-opening the
     * substring hole — "relacional" is not "relacion" plus one of those endings.
     *
     * A term that is a phrase is matched as a whole phrase with the same edges,
     * so "que es" does not match across an unrelated "esque".
     */
    private function matchesTerm(string $haystack, string $term): bool
    {
        $needle = trim($this->normalise($term));

        if ($needle === '') {
            return false;
        }

        $pattern = '/(?<![\p{L}\p{N}])'.preg_quote($needle, '/').'(?:s|es|mente)?(?![\p{L}\p{N}])/u';

        return preg_match($pattern, $haystack) === 1;
    }

    private function previousQuestion(array $recentUserQuestions): ?string
    {
        foreach (array_reverse($recentUserQuestions) as $candidate) {
            $candidate = trim((string) $candidate);

            if ($candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Whether a message reads as a continuation of the previous one.
     *
     * Conservative: a long message that happens to contain "si" is not a
     * follow-up, and a message that names a fresh subject of its own is
     * classified on its own terms.
     */
    private function looksFollowUp(string $haystack, string $previous): bool
    {
        // A message that states its own administrative subject is a new request.
        if ($this->matchedGroups($haystack) !== []) {
            return false;
        }

        foreach (self::FOLLOWUP_TERMS as $term) {
            if ($this->matchesTerm($haystack, $term)) {
                return true;
            }
        }

        // Very short and deictic: "eso", "lo anterior", "el mismo".
        $words = preg_split('/\s+/', trim($haystack)) ?: [];

        if (count($words) <= 8) {
            foreach (['eso', 'esto', 'anterior', 'mismo', 'misma', 'arriba', 'ahi', 'ahí'] as $deictic) {
                if (in_array($deictic, $words, true)) {
                    return true;
                }
            }

            // A short message that opens with a continuation conjunction ("y",
            // "e", "también", "además") continues the request before it. "¿Y en
            // Bogotá qué tal estará?" names a place but no administrative
            // subject, so it is not a new request: it extends the weather
            // question it follows.
            $first = preg_replace('/^[^\p{L}]+/u', '', $words[0] ?? '') ?? '';

            if (in_array($first, ['y', 'e', 'tambien', 'también', 'ademas', 'además'], true)) {
                return true;
            }
        }

        return false;
    }

    private function isFresh(string $haystack): bool
    {
        foreach (self::FRESHNESS_TERMS as $term) {
            if ($this->matchesTerm($haystack, $term)) {
                return true;
            }
        }

        // A year that is this one or later is a currency marker by itself.
        $currentYear = (int) date('Y');

        if (preg_match_all('/\b(20\d{2})\b/', $haystack, $matches)) {
            foreach ($matches[1] as $year) {
                if ((int) $year >= $currentYear) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isWeather(string $haystack): bool
    {
        foreach (self::WEATHER_TERMS as $term) {
            if ($this->matchesTerm($haystack, $term)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A place name the geocoder can use, or null.
     *
     * Deliberately dumb: strip the words that are never part of a place and keep
     * the rest. The weather tool does the progressive fallback, so an over-eager
     * extraction here is corrected there rather than guessed at here.
     */
    private function extractLocation(string $haystack): ?string
    {
        $text = preg_replace('/[^\p{L}\p{N}\s,]/u', ' ', $haystack) ?? $haystack;
        $tokens = preg_split('/[\s,]+/u', trim($text)) ?: [];
        $kept = [];

        foreach ($tokens as $token) {
            $token = trim($token);

            if ($token === '' || in_array($token, self::LOCATION_STOPWORDS, true)) {
                continue;
            }

            $kept[] = $token;
        }

        if ($kept === []) {
            return null;
        }

        return implode(' ', array_slice($kept, 0, 6));
    }

    /**
     * @return list<string>
     */
    private function matchedGroups(string $haystack): array
    {
        $matched = [];

        foreach (self::SUBJECT_TERMS as $group => $terms) {
            foreach ($terms as $term) {
                if ($this->matchesTerm($haystack, $term)) {
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
            if ($this->matchesTerm($haystack, $term)) {
                return true;
            }
        }

        foreach (self::GREETING_TERMS as $term) {
            if ($this->matchesTerm($haystack, $term)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a general request is purely conversational — an explanation or a
     * greeting — and therefore needs no tool at all.
     */
    private function looksConversational(string $haystack): bool
    {
        return $this->matchesAny($haystack, self::CONVERSATIONAL_TERMS)
            || $this->matchesAny($haystack, self::GREETING_TERMS);
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
