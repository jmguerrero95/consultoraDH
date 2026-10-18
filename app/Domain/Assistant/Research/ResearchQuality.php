<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Research;

/**
 * Judges a research turn by what it recovered, not by what it claimed.
 *
 * ## The defect this exists for
 *
 * T02 asked the assistant to investigate recent sodium-battery advances and how
 * they compare with lithium. It made one search, was handed a congress programme
 * that merely mentioned the words, and answered that there was not enough
 * information. The structural cause was that a turn ends the moment the model
 * returns prose: nothing checked whether the sources it received were *about the
 * question*, or whether the answer had given up while the budget still allowed a
 * second, better query.
 *
 * ## What this does, and what it deliberately does not
 *
 * It is a lexical relevance and coverage measure, not a language model: it runs
 * on every turn, costs nothing, and is identical in a test and in production. It
 * answers three narrow questions —
 *
 *   1. Is this source *about* the question's subject (sharing its topic terms),
 *      or is it tangential (a congress programme that shares a word)?
 *   2. How much of the question's subject did the recovered sources cover?
 *   3. Does the reply's own text admit it could not find enough?
 *
 * — and nothing else. It never writes the answer, never decides the query and
 * never encodes a subject: the model still chooses what to search for. It only
 * gives the turn a proportional reason to refuse to stop at "no encontré nada"
 * while a better formulation is still affordable.
 */
final class ResearchQuality
{
    /**
     * Function words, in Spanish and English.
     *
     * @var list<string>
     */
    private const STOPWORDS = [
        'de', 'del', 'la', 'las', 'el', 'los', 'un', 'una', 'unos', 'unas', 'y', 'o', 'u', 'e',
        'en', 'con', 'sin', 'por', 'para', 'que', 'qué', 'como', 'cómo', 'cual', 'cuál', 'cuales',
        'cuáles', 'sobre', 'entre', 'desde', 'hasta', 'hacia', 'segun', 'según', 'al', 'a', 'ante',
        'bajo', 'contra', 'es', 'son', 'esta', 'está', 'estan', 'están', 'ser', 'hay', 'tiene',
        'tienen', 'puede', 'pueden', 'mas', 'más', 'menos', 'muy', 'tambien', 'también', 'pero',
        'su', 'sus', 'mi', 'mis', 'tu', 'tus', 'le', 'les', 'lo', 'se', 'nos', 'me', 'te', 'ya',
        'no', 'si', 'sí', 'the', 'of', 'and', 'or', 'to', 'in', 'on', 'at', 'for', 'with', 'from',
        'by', 'as', 'is', 'are', 'be', 'was', 'were', 'this', 'that', 'these', 'those', 'it', 'its',
        'an', 'a', 'vs', 'versus', 'into', 'about', 'between', 'how', 'what', 'which', 'when',
    ];

    /**
     * Words that frame a research *task* rather than name its *subject*.
     *
     * "avances recientes", "diferencias con", "desarrollos actuales" say what kind
     * of answer is wanted; they are not what the sources must be about. Counting
     * them as topic terms would let a page that merely repeats "recientes" look
     * relevant, and would deflate the coverage of a genuinely on-topic source
     * that happens not to use the word "avance". They are dropped from the term
     * set, so a question reduces to its nouns: baterías, sodio, litio.
     *
     * @var list<string>
     */
    private const RESEARCH_FRAMING = [
        'investiga', 'investigar', 'investigacion', 'investigación', 'busca', 'buscar', 'consulta',
        'consultar', 'averigua', 'averiguar', 'dime', 'cuentame', 'cuéntame', 'explica', 'explicame',
        'explícame', 'resume', 'resumir', 'compara', 'comparar', 'analiza', 'analizar', 'dame',
        'muestra', 'encuentra', 'revisa', 'necesito', 'quiero', 'saber', 'informacion', 'información',
        'avance', 'avances', 'reciente', 'recientes', 'actual', 'actuales', 'actualmente', 'hoy',
        'ultimo', 'último', 'ultima', 'última', 'ultimos', 'últimos', 'ultimas', 'últimas',
        'desarrollo', 'desarrollos', 'diferencia', 'diferencias', 'comparacion', 'comparación',
        'estado', 'situacion', 'situación', 'novedad', 'novedades', 'noticia', 'noticias',
        'ventaja', 'ventajas', 'desventaja', 'desventajas', 'ejemplo', 'ejemplos', 'caso', 'casos',
        'resumen', 'informe', 'general', 'principales', 'claves', 'retos', 'futuro', 'tendencia',
        'tendencias', 'investigacion', 'recent', 'recently', 'advance', 'advances', 'development',
        'developments', 'difference', 'differences', 'comparison', 'current', 'latest', 'today',
        'state', 'status', 'overview', 'news', 'advantages', 'disadvantages', 'example', 'examples',
        'trend', 'trends', 'challenge', 'challenges', 'summary', 'report',
    ];

    /**
     * Phrases a reply uses when it is giving up on the search.
     *
     * Deliberately about the *act of searching*, not about the subject: "no
     * encontré información", "no hay información disponible", "no dispongo de
     * datos suficientes". A reply that says "the source does not state X" is not
     * matched — that is a finding about a source, not a failure to look.
     *
     * @var list<string>
     */
    private const INSUFFICIENCY_MARKERS = [
        'no encontre', 'no encontré', 'no he encontrado', 'no pude encontrar', 'no logre encontrar',
        'no logré encontrar', 'no hay informacion', 'no hay información', 'no existe informacion',
        'no existe información', 'no dispongo de informacion', 'no dispongo de información',
        'informacion insuficiente', 'información insuficiente', 'informacion no disponible',
        'información no disponible', 'sin informacion suficiente', 'sin información suficiente',
        'no tengo informacion', 'no tengo información', 'no hay datos suficientes', 'no encontre fuentes',
        'no encontré fuentes', 'no pude obtener informacion', 'no pude obtener información',
        'no se encontro informacion', 'no se encontró información', 'no cuento con informacion',
        'no cuento con información', 'no arrojo resultados', 'no arrojó resultados',
        'es insuficiente', 'insuficiente para responder', 'no alcanza para responder',
        'i could not find', 'no information', 'insufficient information',
    ];

    /**
     * The terms in a piece of text that name its subject.
     *
     * @return list<string>
     */
    public static function terms(string $text): array
    {
        $normalised = self::normalise($text);
        $words = preg_split('/[^\p{L}\p{N}]+/u', $normalised) ?: [];

        $terms = [];

        foreach ($words as $word) {
            $word = trim($word);

            if (mb_strlen($word) < 4) {
                continue;
            }

            if (in_array($word, self::STOPWORDS, true) || in_array($word, self::RESEARCH_FRAMING, true)) {
                continue;
            }

            $terms[] = $word;
        }

        return array_values(array_unique($terms));
    }

    /**
     * How much of the question's subject a single source covers, from 0 to 1.
     *
     * @param  array<string, mixed>  $source
     */
    public static function relevance(string $question, array $source): float
    {
        $terms = self::terms($question);

        if ($terms === []) {
            return 0.0;
        }

        $haystack = self::normalise(
            (string) ($source['title'] ?? '').' '
            .(string) ($source['snippet'] ?? $source['content'] ?? '').' '
            .(string) ($source['url'] ?? '')
        );

        $hits = 0;

        foreach ($terms as $term) {
            if (str_contains($haystack, $term)) {
                $hits++;
            }
        }

        return $hits / count($terms);
    }

    /**
     * The sources that are about the question at all.
     *
     * A meaningful share of the subject terms, not any single shared function
     * word: a congress programme that only repeats the word "baterías" next to
     * nothing else is exactly the tangential source this must exclude, while a
     * genuine article on the subject shares several, and an article about one
     * dimension (lithium) still qualifies.
     *
     * @param  list<array<string, mixed>>  $sources
     * @return list<array<string, mixed>>
     */
    public static function relevantSources(string $question, array $sources, float $threshold = 0.3): array
    {
        return array_values(array_filter(
            $sources,
            static fn (array $source): bool => self::relevance($question, $source) >= $threshold
        ));
    }

    /**
     * The share of the question's subject terms found across all sources.
     *
     * This is the coverage measure: a run with three sources about sodium
     * batteries but nothing about lithium has covered half of "baterías de sodio
     * frente al litio".
     *
     * @param  list<array<string, mixed>>  $sources
     */
    public static function coverage(string $question, array $sources): float
    {
        $terms = self::terms($question);

        if ($terms === []) {
            return 0.0;
        }

        $haystack = '';

        foreach ($sources as $source) {
            $haystack .= ' '.self::normalise(
                (string) ($source['title'] ?? '').' '
                .(string) ($source['snippet'] ?? $source['content'] ?? '').' '
                .(string) ($source['url'] ?? '')
            );
        }

        if (trim($haystack) === '') {
            return 0.0;
        }

        $covered = 0;

        foreach ($terms as $term) {
            if (str_contains($haystack, $term)) {
                $covered++;
            }
        }

        return $covered / count($terms);
    }

    /**
     * Whether the recovered sources are enough to answer the question.
     *
     * Deliberately a floor and not a guarantee: it reports "there is real,
     * on-topic evidence and it touches most of the subject", which is what a
     * research answer needs before it may stop. A question phrased without any
     * subject term (rare) is treated as sufficient by default, so this can never
     * trap a turn in a loop over a scoring artefact.
     *
     * @param  list<array<string, mixed>>  $sources
     * @return array{sufficient: bool, coverage: float, relevant: int, total: int}
     */
    public static function sufficiency(string $question, array $sources): array
    {
        $terms = self::terms($question);

        if ($terms === []) {
            return ['sufficient' => true, 'coverage' => 1.0, 'relevant' => count($sources), 'total' => count($sources)];
        }

        $relevant = self::relevantSources($question, $sources);
        $coverage = self::coverage($question, $relevant);

        return [
            'sufficient' => $relevant !== [] && $coverage >= 0.5,
            'coverage' => round($coverage, 2),
            'relevant' => count($relevant),
            'total' => count($sources),
        ];
    }

    /**
     * Whether a reply's own text admits the search did not find enough.
     */
    public static function admitsInsufficiency(string $text): bool
    {
        $normalised = self::normalise($text);

        foreach (self::INSUFFICIENCY_MARKERS as $marker) {
            if (str_contains($normalised, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The separate concerns a research question asks about, as focused queries.
     *
     * A heuristic split on the connectors that join distinct questions — "y",
     * "además", ",", "así como" — with the command words stripped, so "avances de
     * las baterías de sodio, diferencias con el litio y desarrollos actuales"
     * becomes three focused phrasings rather than one long one. It is offered to
     * the model as a suggestion (see the research directive), never sent on its
     * own: the model still chooses the queries it issues.
     *
     * @return list<string>
     */
    public static function dimensions(string $question): array
    {
        $parts = preg_split(
            '/\s*(?:,|;|\by\b|\be\b|\bademas\b|\bademás\b|\basi como\b|\basí como\b)\s*/iu',
            $question
        ) ?: [];

        $dimensions = [];

        foreach ($parts as $part) {
            $cleaned = self::stripCommandWords($part);

            if (mb_strlen($cleaned) < 4) {
                continue;
            }

            $dimensions[] = $cleaned;
        }

        return array_values(array_unique(array_slice($dimensions, 0, 4)));
    }

    /**
     * The instruction appended to a turn that gave up while evidence was thin.
     *
     * It states what was actually retrieved (so the model can see the failure of
     * relevance), requires a *different* query — and an English one when the
     * subject is technical — and fixes the honesty rule for the second attempt:
     * the search engine being unavailable is not the subject not existing, and an
     * unverified claim is worse than an explicit gap.
     *
     * @param  list<array<string, mixed>>  $sources
     */
    public static function reformulationDirective(string $question, array $sources): string
    {
        $lines = [
            'Aún no has agotado la búsqueda. Las fuentes recuperadas no cubren la pregunta, así que no puedes',
            'concluir que la información no existe. Antes de responder "no encontré suficiente", haz una segunda',
            'búsqueda con una formulación DISTINTA y más concreta.',
        ];

        $dimensions = self::dimensions($question);

        if ($dimensions !== []) {
            $lines[] = '';
            $lines[] = 'Divide la pregunta en sus partes y busca cada una por separado (no pegues la pregunta completa):';
            foreach ($dimensions as $dimension) {
                $lines[] = '- '.$dimension;
            }
        }

        if ($sources !== []) {
            $lines[] = '';
            $lines[] = 'Fuentes ya recuperadas (valoradas por su pertinencia):';
            foreach (array_slice($sources, 0, 6) as $source) {
                $title = trim((string) ($source['title'] ?? '')) ?: 'sin título';
                $url = trim((string) ($source['url'] ?? ''));
                $score = self::relevance($question, $source);
                $lines[] = '- ['.number_format($score, 2).'] '.$title.($url !== '' ? ' — '.$url : '');
            }
            $lines[] = 'Si una fuente tiene relevancia baja, no es evidencia de la pregunta: no la uses como principal.';
        }

        $lines[] = '';
        $lines[] = 'Reglas para este segundo intento:';
        $lines[] = '- Usa términos concretos y nombres propios; si el tema es técnico (tecnología, ciencia, medicina,';
        $lines[] = '  normas internacionales), repite la búsqueda con language="en" además de en español.';
        $lines[] = '- Lee con web_fetch la fuente más prometedora cuando el fragmento no baste.';
        $lines[] = '- Cita únicamente URLs que aparezcan en los resultados; no inventes ninguna.';
        $lines[] = '- No atribuyas a una fuente una conclusión que no contiene.';
        $lines[] = '- Si el buscador está caído o no devuelve nada, dilo con esas palabras: no significa que la';
        $lines[] = '  información no exista. Explica exactamente qué consultaste, qué encontraste y qué quedó sin';
        $lines[] = '  verificar, en lugar de dar una respuesta sin respaldo.';

        return implode("\n", $lines);
    }

    /**
     * A focused search query derived from a request, rather than the request.
     *
     * The server's own prefetch used to send the person's whole message to the
     * engine, and a whole message is a bad query: "investiga los avances
     * recientes de las baterías de sodio, sus diferencias con el litio y los
     * desarrollos actuales" searches for all of that at once and matches pages
     * that repeat the framing words. Stripping the command words and the opening
     * interrogative leaves the subject.
     *
     * This is only the *preliminary* query. The model still formulates its own
     * afterwards, and is told to split a multi-part request into separate
     * searches.
     */
    public static function searchQuery(string $question, int $maxLength = 220): string
    {
        $cleaned = self::stripCommandWords($question);
        $cleaned = preg_replace(
            '/^(?:cuales son|cuales|cual es|cual|cuales son|que|qué|como|cómo|cuanto|cuánto|cuanta|cuánta|'
            .'donde|dónde|cuando|cuándo|por que|por qué)\s+/iu',
            '',
            $cleaned
        ) ?? $cleaned;
        $cleaned = preg_replace('/\s+/u', ' ', trim($cleaned)) ?? $cleaned;

        if ($cleaned === '') {
            $cleaned = trim($question);
        }

        return mb_substr($cleaned, 0, $maxLength);
    }

    /**
     * Lowercase and strip diacritics, so "Baterías" matches "baterias".
     */
    private static function normalise(string $text): string
    {
        $lower = mb_strtolower($text);

        return strtr($lower, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
            'ä' => 'a', 'ë' => 'e', 'ï' => 'i', 'ö' => 'o', 'ü' => 'u',
            'ñ' => 'n', 'ç' => 'c',
        ]);
    }

    /**
     * Remove the command words that prefix a clause of a research request.
     */
    private static function stripCommandWords(string $part): string
    {
        $cleaned = trim($part);
        $cleaned = preg_replace(
            '/^(?:por favor\s+)?(?:investiga|investigar|busca|buscar|consulta|consultar|averigua|averiguar|'
            .'dime|cuentame|cuéntame|explica|explicame|explícame|resume|resumir|compara|comparar|analiza|'
            .'analizar|dame|muestra|encuentra|revisa|necesito|quiero|saber)\s+(?:sobre\s+|acerca\s+de\s+|'
            .'los\s+|las\s+|el\s+|la\s+)?/iu',
            '',
            $cleaned
        ) ?? $cleaned;

        return trim($cleaned, " \t\n\r\0\x0B.?¿!¡");
    }
}
