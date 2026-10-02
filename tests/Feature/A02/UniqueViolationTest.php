<?php

declare(strict_types=1);

use App\Models\Client;
use App\Support\Database\SchemaConstraint;
use App\Support\Database\UniqueViolation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Only the expected duplicate is translated.
 *
 * A02 caught every `QueryException` and answered "that document already exists",
 * which meant a connection reset, a permissions failure or any other database
 * error came back to the caller as a duplicate key: wrong, and actively harmful,
 * because it told the operator to change something that was not the problem.
 */
beforeEach(function (): void {
    seedPortfolioRoles();
});

/**
 * A QueryException built the way the connection builds a real one: from a PDO
 * exception, so the SQLSTATE lands in `errorInfo[0]` exactly where PostgreSQL puts
 * it. Building it any other way would leave the helper untestable, or worse, would
 * test a shape that never occurs.
 */
function uniqueViolation(string $constraint): QueryException
{
    // `PDOException::$code` is protected, so the SQLSTATE is carried the way
    // PostgreSQL and PDO carry it: in `errorInfo`, with the message repeating it.
    $pdo = new PDOException(sprintf(
        'SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint "%s"',
        $constraint,
    ));

    $pdo->errorInfo = ['23505', '7', 'ERROR'];

    return new QueryException('pgsql', 'insert into clients ...', [], $pdo);
}

/**
 * The same, for something that is not a uniqueness problem at all.
 */
function unrelatedFailure(): QueryException
{
    $pdo = new PDOException('SQLSTATE[42501]: Insufficient privilege: 7 ERROR:  permission denied for table clients');

    $pdo->errorInfo = ['42501', '7', 'ERROR'];

    return new QueryException('pgsql', 'insert into clients ...', [], $pdo);
}

it('recognises the violation of one specific constraint', function (): void {
    $exception = uniqueViolation(SchemaConstraint::CLIENT_DOCUMENT);

    expect(UniqueViolation::isFor($exception, SchemaConstraint::CLIENT_DOCUMENT))->toBeTrue()
        ->and(UniqueViolation::isFor($exception, SchemaConstraint::COMPANY_TAX_ID))->toBeFalse();
});

it('does not treat an unrelated failure as a duplicate', function (): void {
    $exception = unrelatedFailure();

    expect(UniqueViolation::isAny($exception))->toBeFalse()
        ->and(UniqueViolation::isFor($exception, SchemaConstraint::CLIENT_DOCUMENT))->toBeFalse()
        ->and(UniqueViolation::isFor($exception, SchemaConstraint::COMPANY_TAX_ID))->toBeFalse();
});

it('does not treat a duplicate of another constraint as this one', function (): void {
    // The whole point of comparing the constraint name and not only the SQLSTATE:
    // a duplicate NIT is not a duplicate document, and answering the wrong one
    // sends the operator to fix the wrong field.
    $duplicateNit = uniqueViolation(SchemaConstraint::COMPANY_TAX_ID);

    expect(UniqueViolation::isAny($duplicateNit))->toBeTrue()
        ->and(UniqueViolation::isFor($duplicateNit, SchemaConstraint::CLIENT_DOCUMENT))->toBeFalse();
});

it('reads the constraint name out of the message', function (): void {
    expect(UniqueViolation::violatedConstraint(uniqueViolation('companies_tax_id_unique')))
        ->toBe('companies_tax_id_unique')
        ->and(UniqueViolation::violatedConstraint(unrelatedFailure()))->toBeNull();
});

it('answers a duplicate client document as a duplicate, and nothing else', function (): void {
    Client::factory()->create(['document_type' => 'CC', 'document_number' => '12345678']);

    $this->actingAs(actingAsRole())
        ->postJson('/api/clients', clientPayload(['document_number' => '12345678']))
        ->assertStatus(422)
        ->assertJsonPath('errors.document_number.0', 'Ya existe un cliente registrado con ese tipo y número de documento.');
});

it('does not disguise an unrelated database failure as a duplicate', function (): void {
    // A foreign key or a not-null violation arrives as a QueryException too, and
    // it must not be translated: it has to travel to the central handler, which
    // reports it and answers with a safe server error rather than with advice.
    $exception = uniqueViolation('clients_some_other_constraint');

    expect(UniqueViolation::isFor($exception, SchemaConstraint::CLIENT_DOCUMENT))->toBeFalse();
});

it('lets an unexpected database failure reach the central handler', function (): void {
    // The same duplicate document, against a schema whose constraint has a
    // different name: what the A02 controller would have called a duplicate, and
    // this one does not, because it is not the constraint it knows about. It has
    // to travel to the central handler, which reports it and answers with a safe
    // server error.
    //
    // This is what happens in practice when a migration renames a constraint and
    // the code is not updated with it.
    // The document number carries an underscore, which `DocumentNumber::looksValid()`
    // rejects. That is not decoration: it is what makes this test reach the database
    // at all.
    //
    // `StoreClientRequest` runs an existence query as a pre-flight check, and it only
    // runs when the number looks valid. With an ordinary number the pre-flight catches
    // the duplicate first, answers 422 with the duplicate message, and the unique
    // index never fires, so the renamed constraint is irrelevant and the test proves
    // nothing. It was passing for the wrong reason: the pre-flight raises a
    // ValidationException, and until the central handler stopped turning a validation
    // failure into a 500, that surfaced as the 500 this test was asserting.
    Client::factory()->create(['document_type' => 'CC', 'document_number' => 'NO_RECONOCIDO_1']);

    DB::statement('ALTER TABLE clients RENAME CONSTRAINT clients_document_unique TO clients_document_unique_renamed');

    try {
        config(['app.debug' => false]);

        $response = $this->actingAs(actingAsRole())
            ->postJson('/api/clients', clientPayload(['document_number' => 'NO_RECONOCIDO_1']));

        // A server error, not a duplicate, and certainly not "cambie el documento".
        $response->assertStatus(500)
            ->assertJsonPath('code', 'server_error')
            ->assertJsonPath('message', 'Se ha producido un error inesperado. Inténtelo de nuevo más tarde.');

        // And nothing technical reaches the caller.
        $body = $response->getContent();

        expect($body)->not->toContain('SQLSTATE')
            ->and($body)->not->toContain('select ')
            ->and($body)->not->toContain('clients_document_unique_renamed')
            ->and($body)->not->toContain('postgres');
    } finally {
        DB::statement('ALTER TABLE clients RENAME CONSTRAINT clients_document_unique_renamed TO clients_document_unique');
    }
});

it('still answers a duplicate document with 422 when the constraint is named as expected', function (): void {
    // The counterpart of the test above, and the reason it is worth keeping: the
    // pre-flight check and the unique index agree. This is the behaviour that must
    // survive any change to how the central handler classifies exceptions.
    Client::factory()->create(['document_type' => 'CC', 'document_number' => '12345678']);

    config(['app.debug' => false]);

    $this->actingAs(actingAsRole())
        ->postJson('/api/clients', clientPayload(['document_number' => '12345678']))
        ->assertStatus(422)
        ->assertJsonPath('message', 'Ya existe un cliente registrado con ese tipo y número de documento.')
        // A duplicate is a problem the caller can act on, so it must never be
        // reported as an unexpected server fault.
        ->assertJsonMissingPath('code');
});

it('answers a validation failure with 422 even when debug is off', function (): void {
    // A ValidationException is a refusal, not a fault. It used to be reported as a
    // 500 whenever APP_DEBUG was off, because the catch-all render callback treats
    // anything that is not an HTTP exception as a server error. That was invisible
    // in development, where debug is on, and it is why the end to end stack, which
    // runs with debug off, met it first.
    config(['app.debug' => false]);

    $this->actingAs(actingAsRole())
        ->postJson('/api/clients', clientPayload(['document_number' => '']))
        ->assertStatus(422)
        ->assertJsonPath('errors.document_number.0', 'Debe indicar el número de documento.');

    // And on a second rule, so this is not one message hard-coded twice.
    $this->actingAs(actingAsRole())
        ->postJson('/api/clients', clientPayload(['document_number' => str_repeat('9', 33)]))
        ->assertStatus(422)
        ->assertJsonPath('errors.document_number.0', 'El número de documento no puede superar los 32 caracteres.');
});
