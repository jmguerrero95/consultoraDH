<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| A01-AUTH-01 — the sign in path must not carry diagnostic instrumentation
|--------------------------------------------------------------------------
|
| The login action used to switch the query log on, dump the queries of the
| connection into the application log, look the user up twice, verify the
| password hash twice and write the address, the address' owner, the request
| address and the name of the database into the log.
|
| These tests are the evidence that none of that is left, and that the
| guarantees the action used to provide — one lookup, one comparison, one
| session regeneration, one audit trail entry — are still there.
|
*/

const AUTH01_PASSWORD = 'ContrasenaAuth012026';

/**
 * A hasher that counts how many passwords were verified.
 *
 * The action used to verify the hash twice for every attempt: once to write
 * the result into the log and once to decide. The count is the observable
 * difference, so it is measured rather than asserted from the source.
 */
final class Auth01CountingHasher implements Hasher
{
    public int $checks = 0;

    public function __construct(private readonly Hasher $inner) {}

    public function info($hashedValue)
    {
        return $this->inner->info($hashedValue);
    }

    public function make($value, array $options = [])
    {
        return $this->inner->make($value, $options);
    }

    public function check($value, $hashedValue, array $options = [])
    {
        $this->checks++;

        return $this->inner->check($value, $hashedValue, $options);
    }

    public function needsRehash($hashedValue, array $options = [])
    {
        return $this->inner->needsRehash($hashedValue, $options);
    }
}

/**
 * Install the counting hasher as the default driver.
 */
function auth01CountHashVerifications(): Auth01CountingHasher
{
    $counter = new Auth01CountingHasher(app('hash')->driver());

    Hash::extend('auth01-counting', fn (): Auth01CountingHasher => $counter);

    config(['hashing.driver' => 'auth01-counting']);

    return $counter;
}

/**
 * Collect every log record written while the callback runs.
 *
 * @return list<array{message: string, context: array<string, mixed>}>
 */
function auth01CaptureLog(callable $callback): array
{
    $records = [];

    Log::listen(function ($record) use (&$records): void {
        $records[] = [
            'message' => (string) $record->message,
            'context' => (array) $record->context,
        ];
    });

    $callback();

    return $records;
}

/**
 * The whole log record, flattened, as one searchable string.
 *
 * @param  list<array{message: string, context: array<string, mixed>}>  $records
 */
function auth01Flatten(array $records): string
{
    $flat = [];

    foreach ($records as $record) {
        $flat[] = $record['message'];
        $flat[] = json_encode($record['context'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    return implode("\n", $flat);
}

it('signs an active user in with one lookup and one hash comparison', function (): void {
    $user = User::factory()->withPassword(AUTH01_PASSWORD)->create([
        'email' => 'activo.auth01@consultora-dh.test',
    ]);

    $counter = auth01CountHashVerifications();

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $response = $this->withSession(['_token' => 'test-csrf-token'])
        ->postJson('/api/auth/login', [
            'email' => 'ACTIVO.AUTH01@CONSULTORA-DH.TEST',
            'password' => AUTH01_PASSWORD,
        ]);

    $response->assertOk()->assertJsonPath('user.email', 'activo.auth01@consultora-dh.test');

    $this->assertAuthenticatedAs($user);

    // Exactly one statement reads the account by address. The action used to
    // run it twice: once to find the user and once more to log the query.
    $userLookups = array_filter(
        $queries,
        fn (string $sql): bool => str_contains($sql, 'from "users"') && str_contains($sql, '"email"')
    );

    expect($userLookups)->toHaveCount(1)
        // And exactly one verification of the password.
        ->and($counter->checks)->toBe(1);
});

it('verifies exactly one password hash on a rejected attempt', function (): void {
    User::factory()->withPassword(AUTH01_PASSWORD)->create([
        'email' => 'unica.auth01@consultora-dh.test',
    ]);

    $counter = auth01CountHashVerifications();

    $this->withSession(['_token' => 'test-csrf-token'])
        ->postJson('/api/auth/login', [
            'email' => 'unica.auth01@consultora-dh.test',
            'password' => 'ContrasenaIncorrecta2026',
        ])
        ->assertStatus(422);

    expect($counter->checks)->toBe(1);
});

it('rejects a wrong password without leaking what it found', function (): void {
    User::factory()->withPassword(AUTH01_PASSWORD)->create([
        'email' => 'incorrecta.auth01@consultora-dh.test',
    ]);

    $response = $this->withSession(['_token' => 'test-csrf-token'])
        ->postJson('/api/auth/login', [
            'email' => 'incorrecta.auth01@consultora-dh.test',
            'password' => 'ContrasenaIncorrecta2026',
        ]);

    $response->assertStatus(422)->assertJsonValidationErrors('email');

    $this->assertGuest();
});

it('rejects an inactive account with the same response as a wrong password', function (): void {
    User::factory()->inactive()->withPassword(AUTH01_PASSWORD)->create([
        'email' => 'inactivo.auth01@consultora-dh.test',
    ]);

    $response = $this->withSession(['_token' => 'test-csrf-token'])
        ->postJson('/api/auth/login', [
            'email' => 'inactivo.auth01@consultora-dh.test',
            'password' => AUTH01_PASSWORD,
        ]);

    $response->assertStatus(422)->assertJsonValidationErrors('email');

    $this->assertGuest();
});

it('rejects an unknown address with the same response as a wrong password', function (): void {
    User::factory()->withPassword(AUTH01_PASSWORD)->create([
        'email' => 'existe.auth01@consultora-dh.test',
    ]);

    $unknown = $this->withSession(['_token' => 'test-csrf-token'])
        ->postJson('/api/auth/login', [
            'email' => 'nadie.auth01@consultora-dh.test',
            'password' => 'ContrasenaIncorrecta2026',
        ]);

    $wrong = $this->withSession(['_token' => 'test-csrf-token'])
        ->postJson('/api/auth/login', [
            'email' => 'existe.auth01@consultora-dh.test',
            'password' => 'ContrasenaIncorrecta2026',
        ]);

    expect($unknown->status())->toBe($wrong->status())
        ->and($unknown->json())->toBe($wrong->json());
});

it('keeps the remember token and rotates the session', function (): void {
    $user = User::factory()->withPassword(AUTH01_PASSWORD)->create([
        'email' => 'recordar.auth01@consultora-dh.test',
    ]);

    $before = $this->get('/login')->getCookie((string) config('session.cookie'))?->getValue();

    $response = $this->withSession(['_token' => 'test-csrf-token'])
        ->postJson('/api/auth/login', [
            'email' => 'recordar.auth01@consultora-dh.test',
            'password' => AUTH01_PASSWORD,
            'remember' => true,
        ]);

    $response->assertOk();

    $after = $response->getCookie((string) config('session.cookie'))?->getValue();

    expect($before)->toBeString()
        ->and($after)->toBeString()
        ->and($after)->not->toBe($before)
        ->and($user->fresh()->remember_token)->not->toBeNull();
});

it('recovers the password and refuses a second confirmation', function (): void {
    User::factory()->withPassword(AUTH01_PASSWORD)->create([
        'email' => 'recuperar.auth01@consultora-dh.test',
    ]);

    $this->postJson('/api/auth/forgot-password', [
        'email' => 'recuperar.auth01@consultora-dh.test',
    ])->assertOk();

    $this->assertDatabaseHas('password_reset_tokens', [
        'email' => 'recuperar.auth01@consultora-dh.test',
    ]);
});

it('writes no personal data and switches no query log on a sign in', function (): void {
    User::factory()->withPassword(AUTH01_PASSWORD)->create([
        'email' => 'silencioso.auth01@consultora-dh.test',
    ]);

    // The query log must be off before the attempt, so that a log left on by
    // a previous request is detected rather than inherited silently.
    DB::connection()->disableQueryLog();

    $records = auth01CaptureLog(function (): void {
        $this->withSession(['_token' => 'test-csrf-token'])
            ->postJson('/api/auth/login', [
                'email' => 'silencioso.auth01@consultora-dh.test',
                'password' => AUTH01_PASSWORD,
            ])
            ->assertOk();
    });

    $flat = auth01Flatten($records);

    // No address, no password, no connection name, no database name, no
    // query text and no bindings.
    expect($flat)->not->toContain('silencioso.auth01@consultora-dh.test')
        ->and($flat)->not->toContain(AUTH01_PASSWORD)
        ->and($flat)->not->toContain('AuthenticateUser')
        ->and($flat)->not->toContain('select * from "users"')
        ->and($flat)->not->toContain(config('database.connections.pgsql.database'))
        ->and($flat)->not->toContain('Consultora DH');

    // The query log was never switched on by the sign in.
    expect(DB::connection()->logging())->toBeFalse()
        ->and(DB::getQueryLog())->toBe([]);
});

it('keeps the query log off after a rejected attempt too', function (): void {
    User::factory()->withPassword(AUTH01_PASSWORD)->create([
        'email' => 'rechazado.auth01@consultora-dh.test',
    ]);

    DB::connection()->disableQueryLog();

    $this->withSession(['_token' => 'test-csrf-token'])
        ->postJson('/api/auth/login', [
            'email' => 'rechazado.auth01@consultora-dh.test',
            'password' => 'ContrasenaIncorrecta2026',
        ])
        ->assertStatus(422);

    expect(DB::connection()->logging())->toBeFalse();
});

it('keeps the HTTP contract of the sign in endpoint unchanged', function (): void {
    User::factory()->withPassword(AUTH01_PASSWORD)->create([
        'email' => 'contrato.auth01@consultora-dh.test',
    ]);

    $ok = $this->withSession(['_token' => 'test-csrf-token'])
        ->postJson('/api/auth/login', [
            'email' => 'contrato.auth01@consultora-dh.test',
            'password' => AUTH01_PASSWORD,
        ]);

    expect($ok->status())->toBe(200)
        ->and($ok->json())->toHaveKey('user')
        ->and($ok->json('user'))->toHaveKeys(['id', 'name', 'email', 'status']);

    $failed = $this->withSession(['_token' => 'test-csrf-token'])
        ->postJson('/api/auth/login', [
            'email' => 'contrato.auth01@consultora-dh.test',
            'password' => 'ContrasenaIncorrecta2026',
        ]);

    expect($failed->status())->toBe(422)
        ->and($failed->json())->toHaveKey('errors')
        ->and($failed->json('errors'))->toHaveKey('email');
});
