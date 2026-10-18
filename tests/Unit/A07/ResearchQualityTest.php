<?php

declare(strict_types=1);

namespace Tests\Unit\A07;

use App\Domain\Assistant\Research\ResearchQuality;
use PHPUnit\Framework\TestCase;

/**
 * A07-R5-R3 (bloque A) — the research check is judged on evidence, not prose.
 *
 * The T02 defect ended in "no encontré información suficiente" over a source
 * that did not answer the question. These tests pin the measure that makes that
 * outcome distinguishable from a real gap: subject terms, per-source relevance,
 * coverage, and the phrasing that admits a search gave up.
 */
final class ResearchQualityTest extends TestCase
{
    private const QUESTION = 'Investiga los avances recientes de las baterías de sodio y sus diferencias con el litio';

    public function test_terms_keep_the_subject_and_drop_the_framing(): void
    {
        $terms = ResearchQuality::terms(self::QUESTION);

        // The subject nouns survive (normalised, so accented spelling matches).
        $this->assertContains('baterias', $terms);
        $this->assertContains('sodio', $terms);
        $this->assertContains('litio', $terms);

        // The framing words do not: "avances", "recientes", "diferencias"
        // describe the answer wanted, not what sources must be about.
        $this->assertNotContains('avances', $terms);
        $this->assertNotContains('recientes', $terms);
        $this->assertNotContains('diferencias', $terms);
        $this->assertNotContains('investiga', $terms);
    }

    public function test_an_on_topic_source_scores_high_and_a_tangential_one_scores_zero(): void
    {
        $onTopic = [
            'title' => 'Avances recientes en baterías de sodio y su comparación con el litio',
            'snippet' => 'Las baterías de sodio evitan el litio y ofrecen una alternativa más barata.',
        ];

        $tangential = [
            'title' => 'Programa del XII Congreso Latinoamericano de Química',
            'snippet' => 'Incluye sesiones de almacenamiento de energía y nuevas tecnologías del sector.',
        ];

        $this->assertSame(1.0, ResearchQuality::relevance(self::QUESTION, $onTopic));
        $this->assertSame(0.0, ResearchQuality::relevance(self::QUESTION, $tangential));
    }

    public function test_a_source_about_only_one_part_of_the_question_is_still_relevant(): void
    {
        $aboutLithium = [
            'title' => 'Mercado del litio en 2026',
            'snippet' => 'El litio sigue dominando las baterías de vehículos eléctricos.',
        ];

        $this->assertGreaterThanOrEqual(0.3, ResearchQuality::relevance(self::QUESTION, $aboutLithium));
        $this->assertCount(
            1,
            ResearchQuality::relevantSources(self::QUESTION, [$aboutLithium]),
            'A source that answers one dimension must not be discarded as irrelevant.',
        );
    }

    public function test_sufficiency_is_about_a_relevant_floor_and_coverage(): void
    {
        $tangential = [[
            'title' => 'Programa del congreso de química',
            'snippet' => 'Sesiones generales.',
        ]];

        $insufficient = ResearchQuality::sufficiency(self::QUESTION, $tangential);
        $this->assertFalse($insufficient['sufficient'], 'A tangential source is not evidence.');
        $this->assertSame(0, $insufficient['relevant']);

        $sufficient = ResearchQuality::sufficiency(self::QUESTION, [
            ['title' => 'Baterías de sodio: avances', 'snippet' => 'El sodio sustituye al litio en celdas nuevas.'],
        ]);
        $this->assertTrue($sufficient['sufficient'], 'A source covering the whole subject is enough.');
        $this->assertSame(1.0, $sufficient['coverage']);

        // A question with no subject terms cannot trap a turn in a loop.
        $this->assertTrue(ResearchQuality::sufficiency('¿qué es eso?', [])['sufficient']);
    }

    public function test_the_phrasing_that_admits_giving_up_is_recognised(): void
    {
        $this->assertTrue(ResearchQuality::admitsInsufficiency('No encontré información suficiente sobre el tema.'));
        $this->assertTrue(ResearchQuality::admitsInsufficiency('No hay información disponible al respecto.'));
        $this->assertTrue(ResearchQuality::admitsInsufficiency('La información es insuficiente para responder.'));

        // A finding about a source is not a failure to look.
        $this->assertFalse(ResearchQuality::admitsInsufficiency('La fuente no indica el año de publicación, pero describe el avance.'));
        $this->assertFalse(ResearchQuality::admitsInsufficiency('Según el estudio, el costo bajó a 120 dólares por kWh.'));
    }

    public function test_a_multi_part_question_is_split_into_focused_dimensions(): void
    {
        $dimensions = ResearchQuality::dimensions(self::QUESTION);

        $this->assertGreaterThanOrEqual(2, count($dimensions), 'A request with several parts has several dimensions.');

        foreach ($dimensions as $dimension) {
            $this->assertStringNotContainsString('Investiga', $dimension, 'The command word is stripped.');
        }
    }

    public function test_the_preliminary_query_is_the_subject_not_the_whole_message(): void
    {
        $query = ResearchQuality::searchQuery('Investiga los avances recientes de las baterías de sodio');

        $this->assertStringNotContainsString('Investiga', $query);
        $this->assertStringContainsString('baterías de sodio', $query);

        // A message that is nothing but a command still yields something.
        $this->assertNotSame('', ResearchQuality::searchQuery('busca'));
    }

    public function test_the_directive_states_what_was_retrieved_and_the_honesty_rules(): void
    {
        $directive = ResearchQuality::reformulationDirective(self::QUESTION, [[
            'title' => 'Programa del congreso',
            'url' => 'https://ejemplo.test/congreso',
            'snippet' => 'Sesiones generales.',
        ]]);

        $this->assertStringContainsString('no puedes', $directive);
        $this->assertStringContainsString('language="en"', $directive);
        $this->assertStringContainsString('No atribuyas a una fuente', $directive);
        $this->assertStringContainsString('no significa que la', $directive);
        // The tangential source is shown with its (low) relevance score.
        $this->assertStringContainsString('https://ejemplo.test/congreso', $directive);
        $this->assertStringContainsString('[0.00]', $directive);
    }

    public function test_cited_urls_extracts_http_links_from_text(): void
    {
        $text = 'Según https://energia.test/sodio y https://mineria.test/litio, el sodio avanza.';
        $urls = ResearchQuality::citedUrls($text);

        $this->assertCount(2, $urls);
        $this->assertContains('https://energia.test/sodio', $urls);
        $this->assertContains('https://mineria.test/litio', $urls);
    }

    public function test_cites_sources_detects_recovered_url_in_response(): void
    {
        $sources = [
            ['url' => 'https://energia.test/sodio', 'title' => 'Sodio'],
            ['url' => 'https://mineria.test/litio', 'title' => 'Litio'],
        ];

        $this->assertTrue(ResearchQuality::citesSources('Ver https://energia.test/sodio para detalles.', $sources));
        $this->assertTrue(ResearchQuality::citesSources('Fuentes: https://mineria.test/litio y https://energia.test/sodio.', $sources));
        $this->assertFalse(ResearchQuality::citesSources('El sodio es mejor que el litio.', $sources));
        $this->assertFalse(ResearchQuality::citesSources('Ver https://otro.test/no-recuperado.', $sources));
        $this->assertFalse(ResearchQuality::citesSources('', $sources));
        $this->assertFalse(ResearchQuality::citesSources('Sin fuentes.', []));
    }

    public function test_covers_dimensions_checks_each_question_part(): void
    {
        $question = 'Avances de sodio y diferencias con litio';

        $this->assertTrue(ResearchQuality::coversDimensions($question, 'El sodio avanza y difiere del litio.'));
        $this->assertTrue(ResearchQuality::coversDimensions($question, 'Sodio: avances. Litio: comparación.'));
        $this->assertFalse(ResearchQuality::coversDimensions($question, 'Solo sodio avanza.'));
        $this->assertFalse(ResearchQuality::coversDimensions($question, 'Nada sobre litio aquí.'));
        $this->assertTrue(ResearchQuality::coversDimensions('¿qué es?', 'Respuesta corta.'), 'No dimensions to cover.');
    }
}
