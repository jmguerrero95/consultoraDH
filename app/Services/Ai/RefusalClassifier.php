<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * Tells a refusal apart from an answer, and tells one kind of refusal from
 * another.
 *
 * ## The defect this exists for
 *
 * The T04 live run asked for a PDF and was answered, by the free model the pool
 * happened to reach, with "Lo siento, pero no puedo cumplir con esa solicitud."
 * The turn accepted that as the answer: the correction that exists for "no puedo
 * crear archivos" never fired, because that check required the refusal to *name*
 * a file. The person got a refusal where the authorised `create_artifact` would
 * have produced the document.
 *
 * ## The distinctions that matter
 *
 * A refusal is not one thing, and treating every "no puedo" alike is wrong in
 * both directions:
 *
 *   - **Policy** — an *explicit* safety or content-policy refusal. It is a
 *     decision about the request itself, so it is delivered, never recovered,
 *     and never re-asked of another provider. This is the one refusal that must
 *     never be evaded. The provider's own `finish_reason: content_filter` is the
 *     authoritative form of it; this recognises the verbal form so a model that
 *     states the policy in prose is treated the same way.
 *   - **Capability** — the model says it cannot produce, e.g., a document, while
 *     the tool that does exactly that is offered. The named-file form.
 *   - **Unproductive** — a generic refusal or deflection with no policy signal,
 *     in a turn that asked for a file and held `create_artifact`. The model
 *     declined to use the capability it had; there is nothing here that says the
 *     request itself is disallowed, so one bounded recovery is allowed.
 *
 * The other two outcomes the caller distinguishes are not properties of a reply:
 * **no authorised tool** (nothing to recover with) and **no budget or quota**
 * (no room to recover). Both are decided by the caller from what it offered and
 * what the turn has left, and both mean no recovery happens.
 */
final class RefusalClassifier
{
    /** The reply is an answer (or at least not a refusal this class knows). */
    public const NONE = 'none';

    /** An explicit safety or content-policy refusal. Never recovered. */
    public const POLICY = 'policy';

    /** The model says it cannot do what an offered tool does. */
    public const CAPABILITY = 'capability';

    /** A generic, unproductive refusal in a turn that needed a file. */
    public const UNPRODUCTIVE = 'unproductive';

    /**
     * The request asked for a file the assistant can generate.
     *
     * Deliberately about an explicit deliverable — a format or the words
     * "archivo", "documento", "descargable" — so a request for a *verbal*
     * summary is not pushed into generating a file.
     *
     * @var list<string>
     */
    private const DELIVERABLE_MARKERS = [
        'pdf', 'docx', 'doc', 'word', 'xlsx', 'xls', 'excel', 'csv', 'txt', 'md', 'markdown',
        'pptx', 'powerpoint', 'archivo', 'documento', 'descargable', 'para descargar', 'editable',
        'planilla', 'plantilla', 'diapositiva', 'presentacion',
    ];

    /**
     * Verbs that ask for a file to be produced, paired with a deliverable mark
     * above so ["documento"] alone does not force generation.
     *
     * @var list<string>
     */
    private const GENERATION_MARKERS = [
        'genera', 'generar', 'generame', 'generalo', 'generala', 'crea', 'crear', 'creame',
        'exporta', 'exportar', 'exportame', 'convierte', 'convertir', 'conviertelo',
        'descargable', 'para descargar',
    ];

    /**
     * Words that say a refusal is about content policy rather than capability.
     *
     * Kept deliberately about *safety* language. A generic "no puedo cumplir con
     * esa solicitud" is not matched: it names no policy and no harm, so it is
     * treated as an unproductive capability decline, which is what it is.
     *
     * @var list<string>
     */
    private const POLICY_MARKERS = [
        'contra mis normas', 'contra mis directrices', 'contra mis politicas', 'contra mis principios',
        'politica de contenido', 'politicas de contenido', 'normas de contenido', 'normas de seguridad',
        'directrices de contenido', 'contenido danino', 'contenido peligroso', 'contenido ilegal',
        'contenido violento', 'contenido sexual', 'actividad ilegal', 'actividades ilegales',
        'no puedo ayudar con eso', 'no puedo ayudarte con eso', 'no puedo asistirte con eso',
        'no puedo asistir con eso', 'no puedo participar en', 'no puedo proporcionar esa informacion',
        'against my guidelines', 'against my policy', 'against my principles', 'can\'t help with that',
        'cannot help with that', 'can\'t assist with that', 'cannot assist with that', 'harmful content',
        'illegal activity',
    ];

    /**
     * The negative constructions a model uses before failing a request.
     *
     * @var list<string>
     */
    private const REFUSAL_MARKERS = [
        'no puedo', 'no podre', 'no podré', 'no me es posible', 'no soy capaz', 'no estoy en capacidad',
        'no estoy capacitado', 'no estoy autorizado', 'carezco de', 'no tengo', 'no dispongo',
        'lo siento', 'prefiero no', 'no voy a poder', 'no se me permite', 'no me esta permitido',
    ];

    /**
     * The words that, next to a refusal, name the capability being declined.
     *
     * @var list<string>
     */
    private const CAPABILITY_OBJECTS = [
        'archivo', 'archivos', 'documento', 'documentos', 'pdf', 'pdfs', 'excel', 'excels', 'word',
        'xlsx', 'docx', 'csv', 'informe', 'informes', 'reporte', 'hoja', 'adjunto', 'plantilla',
        'descarga', 'descargar', 'descargable',
    ];

    /**
     * Classify a tool-free reply.
     *
     * @param  bool  $capabilityOffered  the turn offered the tool this recovery
     *                                   would use (`create_artifact`)
     * @param  bool  $deliverableRequested  the question asked for a file
     */
    public static function classify(string $reply, bool $capabilityOffered, bool $deliverableRequested): string
    {
        $text = self::normalise($reply);

        if (trim($text) === '') {
            return self::NONE;
        }

        // An explicit safety refusal is decided first and is terminal: it is
        // never a capability problem and is never recovered.
        if (self::policy($text)) {
            return self::POLICY;
        }

        // Nothing to recover with: the tool is not authorised for this turn.
        if (! $capabilityOffered) {
            return self::NONE;
        }

        if (self::capability($text)) {
            return self::CAPABILITY;
        }

        if ($deliverableRequested && self::refuses($text)) {
            return self::UNPRODUCTIVE;
        }

        return self::NONE;
    }

    /**
     * Whether an explicit safety or content-policy refusal is stated.
     */
    public static function policy(string $reply): bool
    {
        $text = self::normalise($reply);

        foreach (self::POLICY_MARKERS as $marker) {
            if (str_contains($text, $marker)) {
                return true;
            }
        }

        // "política/normas/directrices" next to content or safety, in any order
        // within one sentence.
        return (bool) preg_match(
            '/(politica|normas?|directrices|lineamientos)[^.]{0,40}(contenido|seguridad|uso)/u',
            $text
        );
    }

    /**
     * Whether the reply declines a document while naming the document.
     */
    public static function capability(string $reply): bool
    {
        $text = self::normalise($reply);

        if (! self::refuses($text)) {
            return false;
        }

        foreach (self::CAPABILITY_OBJECTS as $object) {
            if (str_contains($text, $object)) {
                return true;
            }
        }

        return (bool) preg_match(
            '/(crear|generar|producir|exportar|elaborar|redactar|convertir|entregar)[^.]{0,60}'
            .'(archivo|documento|pdf|excel|word|xlsx|docx|csv|informe|hoja|adjunto)/u',
            $text
        );
    }

    /**
     * Whether the reply carries a negative or deflection marker at all.
     */
    public static function refuses(string $reply): bool
    {
        $text = self::normalise($reply);

        foreach (self::REFUSAL_MARKERS as $marker) {
            if (str_contains($text, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a question asks for a file the assistant can generate.
     */
    public static function requestsFileDeliverable(string $question): bool
    {
        return self::containsMarker(self::normalise($question), self::DELIVERABLE_MARKERS);
    }

    /**
     * The request asked to *produce* a file, not merely mentioned one.
     *
     * `requestsFileDeliverable` is deliberately broad so a refusal can be
     * recognised as declining a capability the turn has ("resume este
     * documento" names a document). Recovery for a file that was not produced
     * has to be narrower: it is only a contract failure when the person asked
     * for the file to be generated, exported or made downloadable. Both a
     * deliverable marker and a generation verb (or the explicit "descargable"
     * wording) are required, so a question *about* a document is never pushed
     * into generating one.
     */
    public static function requestsFileGeneration(string $question): bool
    {
        $text = self::normalise($question);

        return self::containsMarker($text, self::DELIVERABLE_MARKERS)
            && self::containsMarker($text, self::GENERATION_MARKERS);
    }

    /**
     * Whether one of the markers appears as a whole word.
     *
     * @param  list<string>  $markers
     */
    private static function containsMarker(string $text, array $markers): bool
    {
        foreach ($markers as $marker) {
            if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($marker, '/').'(?![\p{L}\p{N}])/u', $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lowercase and strip diacritics, so "políticas" matches "politicas".
     */
    private static function normalise(string $text): string
    {
        $lower = mb_strtolower(strip_tags($text));

        return strtr($lower, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
            'ä' => 'a', 'ë' => 'e', 'ï' => 'i', 'ö' => 'o', 'ü' => 'u',
            'ñ' => 'n', 'ç' => 'c',
        ]);
    }
}
