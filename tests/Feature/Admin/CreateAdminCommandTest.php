<?php

declare(strict_types=1);

use App\Domain\Audit\AuditAction;
use App\Domain\Users\UserStatus;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\PendingCommand;
use Spatie\Permission\Models\Role;

/** Roles must exist before the command can assign them. */
beforeEach(function (): void {
    Role::query()->firstOrCreate([
        'name' => 'Super Admin',
        'guard_name' => 'web',
    ]);
});

afterEach(function (): void {
    // The command reads these from the environment, so a test that sets them
    // must not leave them behind for the next one.
    unset($_ENV['CONSULTORA_DH_ADMIN_PASSWORD'], $_SERVER['CONSULTORA_DH_ADMIN_PASSWORD']);
    unset($_ENV['CONSULTORA_DH_ADMIN_PASSWORD_CONFIRMATION'], $_SERVER['CONSULTORA_DH_ADMIN_PASSWORD_CONFIRMATION']);

    putenv('CONSULTORA_DH_ADMIN_PASSWORD');
    putenv('CONSULTORA_DH_ADMIN_PASSWORD_CONFIRMATION');
});

/**
 * Supply the password the way automation is meant to: through the environment.
 */
function adminPassword(string $password = 'ConsulTora2026Dh'): void
{
    $_ENV['CONSULTORA_DH_ADMIN_PASSWORD'] = $password;
    $_SERVER['CONSULTORA_DH_ADMIN_PASSWORD'] = $password;
    $_ENV['CONSULTORA_DH_ADMIN_PASSWORD_CONFIRMATION'] = $password;
    $_SERVER['CONSULTORA_DH_ADMIN_PASSWORD_CONFIRMATION'] = $password;

    putenv("CONSULTORA_DH_ADMIN_PASSWORD={$password}");
    putenv("CONSULTORA_DH_ADMIN_PASSWORD_CONFIRMATION={$password}");
}

function runCreateAdmin(array $arguments = []): PendingCommand
{
    return test()->artisan('consultora-dh:create-admin', $arguments);
}

// --- The password option must not exist ------------------------------------

it('does not offer a password option at all', function (): void {
    $definition = Artisan::all()['consultora-dh:create-admin']->getDefinition();

    // The strongest form of the requirement: the option is not merely ignored,
    // it does not exist, so there is no way to pass a password as an argument.
    expect($definition->hasOption('password'))->toBeFalse();

    // The only project options left are the three non-sensitive ones.
    $options = array_keys($definition->getOptions());

    expect($options)->toContain('name')
        ->toContain('email')
        ->toContain('role')
        ->not->toContain('password');
});

it('refuses a password passed as a command line argument', function (): void {
    adminPassword();

    // Symfony rejects an undefined option outright, before the command runs.
    // This is the behaviour that matters: the argument never reaches the
    // command, so it cannot end up in the log or in a user record.
    try {
        runCreateAdmin([
            '--name' => 'Ana Restrepo',
            '--email' => 'ana@consultora-dh.test',
            '--password' => 'ConsulTora2026Dh',
        ])->run();

        $this->fail('the command accepted a password option');
    } catch (Throwable $e) {
        expect($e->getMessage())->toContain('The "--password" option does not exist');
    }

    expect(User::query()->count())->toBe(0);
});

// --- The supported mechanisms ----------------------------------------------

it('creates an active administrator with the Super Admin role', function (): void {
    adminPassword();

    runCreateAdmin([
        '--name' => 'Ana Restrepo',
        '--email' => 'Admin@Consultora-DH.test',
    ])->assertSuccessful();

    $admin = User::query()->where('email', 'admin@consultora-dh.test')->firstOrFail();

    expect($admin->name)->toBe('Ana Restrepo')
        ->and($admin->status)->toBe(UserStatus::Active)
        ->and($admin->isActive())->toBeTrue()
        ->and($admin->hasRole('Super Admin'))->toBeTrue();
});

it('reads the password from the environment variables', function (): void {
    $password = 'ClaveDesdeEntorno2026';
    adminPassword($password);

    runCreateAdmin([
        '--name' => 'Ana Restrepo',
        '--email' => 'ana@consultora-dh.test',
    ])->assertSuccessful();

    $admin = User::query()->where('email', 'ana@consultora-dh.test')->firstOrFail();

    expect($admin->password)->not->toBe($password)
        ->and(Hash::check($password, $admin->password))->toBeTrue();
});

it('never echoes the password', function (): void {
    $password = 'ClaveDelicada2026';
    adminPassword($password);

    $this->artisan('consultora-dh:create-admin', [
        '--name' => 'Ana Restrepo',
        '--email' => 'ana@consultora-dh.test',
    ])->assertSuccessful();

    expect(Artisan::output())->not->toContain($password);
});

it('rejects a mismatched confirmation from the environment', function (): void {
    $_ENV['CONSULTORA_DH_ADMIN_PASSWORD'] = 'ConsulTora2026Dh';
    $_ENV['CONSULTORA_DH_ADMIN_PASSWORD_CONFIRMATION'] = 'OtraClaveDistinta2026';

    runCreateAdmin([
        '--name' => 'Ana Restrepo',
        '--email' => 'ana@consultora-dh.test',
    ])->assertFailed();

    expect(User::query()->count())->toBe(0);
});

it('prompts with a hidden field when the environment variables are absent', function (): void {
    $this->artisan('consultora-dh:create-admin')
        ->expectsQuestion('Nombre completo', 'Ana Restrepo')
        ->expectsQuestion('Correo electrónico', 'ana@consultora-dh.test')
        ->expectsQuestion('Contraseña', 'ConsulTora2026Dh')
        ->expectsQuestion('Confirme la contraseña', 'ConsulTora2026Dh')
        ->assertSuccessful();

    expect(User::query()->where('email', 'ana@consultora-dh.test')->exists())->toBeTrue();
});

it('does not echo a password typed at the prompt', function (): void {
    $this->artisan('consultora-dh:create-admin')
        ->expectsQuestion('Nombre completo', 'Ana Restrepo')
        ->expectsQuestion('Correo electrónico', 'ana@consultora-dh.test')
        ->expectsQuestion('Contraseña', 'ClaveDelicada2026')
        ->expectsQuestion('Confirme la contraseña', 'ClaveDelicada2026')
        ->assertSuccessful();

    expect(Artisan::output())->not->toContain('ClaveDelicada2026');
});

it('fails without a password and without a prompt when non interactive', function (): void {
    runCreateAdmin([
        '--name' => 'Ana Restrepo',
        '--email' => 'ana@consultora-dh.test',
    ])->assertFailed();

    expect(User::query()->count())->toBe(0);
});

// --- Existing behaviour ----------------------------------------------------

it('normalises the address to lower case', function (): void {
    adminPassword();

    runCreateAdmin([
        '--name' => 'Ana Restrepo',
        '--email' => '  ADMIN@Consultora-DH.TEST ',
    ])->assertSuccessful();

    expect(User::query()->where('email', 'admin@consultora-dh.test')->exists())->toBeTrue();
});

it('rejects a duplicate address, including one that differs only in case', function (): void {
    User::factory()->create(['email' => 'existente@consultora-dh.test']);
    adminPassword();

    runCreateAdmin([
        '--name' => 'Otra Persona',
        '--email' => 'EXISTENTE@consultora-dh.test',
    ])->assertFailed();

    expect(User::query()->count())->toBe(1);
});

it('rejects a password that does not meet the policy', function (): void {
    adminPassword('corta');

    runCreateAdmin([
        '--name' => 'Ana Restrepo',
        '--email' => 'nuevo@consultora-dh.test',
    ])->assertFailed();

    expect(User::query()->where('email', 'nuevo@consultora-dh.test')->exists())->toBeFalse();
});

it('requires a name and an address', function (): void {
    adminPassword();

    runCreateAdmin(['--email' => 'sin-nombre@consultora-dh.test'])->assertFailed();
    runCreateAdmin(['--name' => 'Sin Correo'])->assertFailed();

    expect(User::query()->count())->toBe(0);
});

it('validates the address format', function (): void {
    adminPassword();

    runCreateAdmin([
        '--name' => 'Correo Inválido',
        '--email' => 'esto-no-es-un-correo',
    ])->assertFailed();

    expect(User::query()->count())->toBe(0);
});

it('records the creation in the audit trail', function (): void {
    adminPassword();

    runCreateAdmin([
        '--name' => 'Ana Restrepo',
        '--email' => 'ana@consultora-dh.test',
    ])->assertSuccessful();

    $event = AuditEvent::query()
        ->where('action', AuditAction::AdministratorCreated->value)
        ->firstOrFail();

    $admin = User::query()->where('email', 'ana@consultora-dh.test')->firstOrFail();

    expect($event->user_id)->toBe($admin->id)
        ->and($event->metadata['email'])->toBe('ana@consultora-dh.test')
        ->and($event->metadata['roles'])->toContain('Super Admin');
});

it('fails when the requested role does not exist', function (): void {
    adminPassword();

    runCreateAdmin([
        '--name' => 'Ana Restrepo',
        '--email' => 'ana@consultora-dh.test',
        '--role' => 'Rol Inexistente',
    ])->assertFailed();

    expect(User::query()->where('email', 'ana@consultora-dh.test')->exists())->toBeFalse();
});
