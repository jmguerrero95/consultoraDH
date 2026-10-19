<?php

declare(strict_types=1);

namespace Tests\Unit\A07;

use App\Services\Ai\RefusalClassifier;
use Tests\TestCase;

/**
 * A07-RUNTIME-RELIABILITY — telling the kinds of refusal apart.
 *
 * The T04 live run delivered "Lo siento, pero no puedo cumplir con esa
 * solicitud." as the answer to a PDF request, because the correction that exists
 * for "no puedo crear archivos" required the refusal to *name* a file. These pin
 * the five outcomes the caller must be able to distinguish:
 *
 *   A. policy          — an explicit safety refusal: delivered, never recovered.
 *   B. capability      — "no puedo crear archivos": recoverable, tool offered.
 *   C. unproductive    — a generic refusal for a file request: recoverable.
 *   D. no authorised tool — nothing to recover with: not a recoverable refusal.
 *   E. no budget/quota — decided by the caller, never by the text.
 */
final class RefusalClassificationTest extends TestCase
{
    // -------------------------------------------------------------- A: policy
    public function test_an_explicit_safety_refusal_is_policy_and_is_never_recoverable(): void
    {
        $reply = 'No puedo ayudarte con eso; va contra mis normas de seguridad.';

        $this->assertTrue(RefusalClassifier::policy($reply));
        $this->assertSame(RefusalClassifier::POLICY, RefusalClassifier::classify($reply, true, true));
    }

    public function test_a_stated_content_policy_is_policy(): void
    {
        $reply = 'Lo siento, no puedo generar contenido dañino ni participar en actividades ilegales.';

        $this->assertSame(RefusalClassifier::POLICY, RefusalClassifier::classify($reply, true, true));
    }

    // ---------------------------------------------------------- B: capability
    public function test_a_named_document_refusal_is_capability(): void
    {
        $reply = 'No puedo crear archivos PDF ni Excel, lo siento.';

        $this->assertSame(RefusalClassifier::CAPABILITY, RefusalClassifier::classify($reply, true, true));
    }

    // -------------------------------------------------------- C: unproductive
    public function test_a_generic_refusal_for_a_file_request_is_unproductive(): void
    {
        // The exact T04 shape: no file named, no policy stated.
        $reply = 'Lo siento, pero no puedo cumplir con esa solicitud.';

        $this->assertFalse(RefusalClassifier::policy($reply));
        $this->assertFalse(RefusalClassifier::capability($reply));
        $this->assertSame(RefusalClassifier::UNPRODUCTIVE, RefusalClassifier::classify($reply, true, true));
    }

    public function test_a_generic_refusal_is_not_unproductive_when_no_file_was_asked_for(): void
    {
        $reply = 'Lo siento, pero no puedo cumplir con esa solicitud.';

        // Without a deliverable the recovery prompt would be off-topic: leave it.
        $this->assertSame(RefusalClassifier::NONE, RefusalClassifier::classify($reply, true, false));
    }

    // -------------------------------------------------- D: no authorised tool
    public function test_a_refusal_without_the_authorised_tool_is_not_a_recoverable_refusal(): void
    {
        $reply = 'No puedo crear archivos PDF.';

        // The person may not use create_artifact: there is nothing to recover with.
        $this->assertSame(RefusalClassifier::NONE, RefusalClassifier::classify($reply, false, true));
    }

    // ------------------------------------------------------------- not a "no"
    public function test_a_real_answer_is_not_a_refusal(): void
    {
        $reply = 'Aquí tienes las recomendaciones: usa contraseñas robustas y activa el doble factor.';

        $this->assertSame(RefusalClassifier::NONE, RefusalClassifier::classify($reply, true, true));
    }

    // ------------------------------------------------------------- deliverable
    public function test_a_file_request_is_recognised_by_its_explicit_deliverable(): void
    {
        $this->assertTrue(RefusalClassifier::requestsFileDeliverable(
            "Crea un PDF profesional titulado 'Guía básica de seguridad informática para pequeñas empresas'."
        ));

        $this->assertTrue(RefusalClassifier::requestsFileDeliverable(
            'Genera un informe ejecutivo en Word … Debe ser un DOCX editable y descargable.'
        ));

        // A verbal request is not pushed into generating a file.
        $this->assertFalse(RefusalClassifier::requestsFileDeliverable(
            '¿Cuántos clientes tenemos y cómo está la cartera?'
        ));
    }
}
