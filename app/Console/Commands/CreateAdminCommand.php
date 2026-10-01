<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Users\Events\AdministratorCreated;
use App\Domain\Users\UserStatus;
use App\Models\User;
use App\Support\Security\PasswordPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Throwable;

/**
 * Creates the first Super Administrator of the installation.
 *
 * Security properties:
 *  - The password is NEVER accepted as a command line argument. Values given to
 *    a process are visible in the shell history, in `ps` output and in the logs
 *    of whatever automation runs the command, so `--password` does not exist.
 *    It arrives either through the hidden interactive prompt or, for automated
 *    provisioning, through the CONSULTORA_DH_ADMIN_PASSWORD environment
 *    variables, and is never echoed.
 *  - The address is normalised to lower case and rejected when it is taken.
 *  - The password policy is the same one the interface enforces.
 *  - The account is created active and receives the Super Admin role.
 *  - The creation is written to the audit trail.
 *
 * `--no-interaction` is Symfony's global flag. Used together with
 * CONSULTORA_DH_ADMIN_PASSWORD and CONSULTORA_DH_ADMIN_PASSWORD_CONFIRMATION it
 * makes the command fully non interactive, which is how scripts/run-e2e.sh
 * provisions its account.
 */
final class CreateAdminCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'consultora-dh:create-admin
                            {--name= : Nombre completo. Si se omite, se solicita de forma interactiva}
                            {--email= : Correo electrónico. Si se omite, se solicita de forma interactiva}
                            {--role= : Rol a asignar. Por defecto Super Admin}';

    /**
     * @var string
     */
    protected $description = 'Crea una cuenta de administrador con el rol indicado';

    /**
     * The role that receives every permission.
     */
    private const SUPER_ADMIN = 'Super Admin';

    public function handle(): int
    {
        try {
            $name = $this->resolveName();
            $email = $this->resolveEmail();
            $password = $this->resolvePassword();
            $roleName = $this->resolveRoleName();
        } catch (ValidationException $e) {
            $this->error($e->validator->errors()->first());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('No fue posible crear el administrador: '.$e->getMessage());

            return self::FAILURE;
        }

        // The role must exist before the assignment; A01 seeds it, but a fresh
        // database may not have been seeded yet.
        if (Role::query()->where('name', $roleName)->doesntExist()) {
            $this->error("El rol \"{$roleName}\" no existe. Ejecute `php artisan db:seed` primero.");

            return self::FAILURE;
        }

        try {
            $admin = DB::transaction(function () use ($name, $email, $password, $roleName): User {
                $admin = User::query()->create([
                    'name' => $name,
                    'email' => $email,
                    'password' => $password,
                    'status' => UserStatus::Active,
                ]);

                $admin->assignRole($roleName);

                return $admin;
            });
        } catch (Throwable $e) {
            $this->error('No fue posible crear el administrador: '.$e->getMessage());

            return self::FAILURE;
        }

        event(new AdministratorCreated($admin));

        $this->newLine();
        $this->info('Administrador creado correctamente.');
        $this->components->twoColumnDetail('Nombre', $admin->name);
        $this->components->twoColumnDetail('Correo electrónico', $admin->email);
        $this->components->twoColumnDetail('Rol', $roleName);
        $this->components->twoColumnDetail('Estado', $admin->status->label());

        return self::SUCCESS;
    }

    private function resolveName(): string
    {
        $name = $this->option('name')
            ?: $this->ask('Nombre completo', null, static fn (): bool => $this->input->isInteractive());

        return $this->validate($name, 'nombre', [
            'required' => 'El nombre es obligatorio.',
            'min' => 'El nombre debe tener al menos 3 caracteres.',
            'max' => 'El nombre no puede superar los 120 caracteres.',
        ], 'required|string|min:3|max:120');
    }

    private function resolveEmail(): string
    {
        $email = $this->option('email')
            ?: $this->ask('Correo electrónico', null, static fn (): bool => $this->input->isInteractive());

        $email = mb_strtolower(trim((string) $email));

        return $this->validate($email, 'correo electrónico', [
            'required' => 'El correo electrónico es obligatorio.',
            'email' => 'El correo electrónico no tiene un formato válido.',
            'max' => 'El correo electrónico no puede superar los 255 caracteres.',
            'unique' => 'Ya existe una cuenta registrada con ese correo electrónico.',
        ], 'required|string|email:rfc|max:255|unique:users,email');
    }

    /**
     * Resolution order: the environment variables used by automated
     * provisioning, then a hidden interactive prompt. There is deliberately no
     * command line option: a password passed as an argument is recorded in the
     * shell history, shown by `ps` to every user on the machine and captured in
     * the logs of any tool that runs the command, none of which the hidden
     * prompt or an environment variable does.
     *
     * The value is hashed immediately and never returned to the console.
     */
    private function resolvePassword(): string
    {
        $fromEnvironment = env('CONSULTORA_DH_ADMIN_PASSWORD');

        if (is_string($fromEnvironment) && $fromEnvironment !== '') {
            $password = $fromEnvironment;
            $confirmation = (string) env('CONSULTORA_DH_ADMIN_PASSWORD_CONFIRMATION', '');
        } else {
            $password = (string) $this->secret('Contraseña');
            $confirmation = (string) $this->secret('Confirme la contraseña');
        }

        if (! hash_equals($password, $confirmation)) {
            throw ValidationException::withMessages([
                'contraseña' => 'La confirmación de contraseña no coincide.',
            ]);
        }

        // The shared policy, without the "confirmed" rule because the
        // confirmation was already checked above.
        $rule = PasswordPolicy::rule();

        $validator = Validator::make(
            ['password' => $password],
            ['password' => ['required', 'string', 'max:255', $rule]],
            [
                'required' => 'La contraseña es obligatoria.',
                'max' => 'La contraseña no puede superar los 255 caracteres.',
            ] + PasswordPolicy::messages(),
            ['password' => 'contraseña'],
        );

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $password;
    }

    private function resolveRoleName(): string
    {
        $role = $this->option('role') ?: self::SUPER_ADMIN;

        return (string) $role;
    }

    /**
     * @param  array<string, string>  $messages
     */
    private function validate(?string $value, string $label, array $messages, string $rules): string
    {
        $validator = Validator::make(
            [$label => $value],
            [$label => explode('|', $rules)],
            $messages,
            [$label => $label],
        );

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return trim((string) $value);
    }
}
