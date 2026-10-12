<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Generate the Web Push VAPID key pair.
 *
 * ## Why this exists
 *
 * Web Push needs an asymmetric key pair: the browser encrypts a notification to
 * the public key, and only the private key can produce something the push
 * service will accept. A06 shipped `WebPushService` and the subscription
 * endpoints, but no way to produce the keys, so every deployment had to invent
 * one by hand. This closes that gap with the same library that performs the
 * delivery, so the key pair is guaranteed to be one the library accepts.
 *
 * ## What it deliberately does not do
 *
 * It never writes the private key to the repository, to a log, or to stdout. A
 * key printed to a terminal ends up in a shell history and in a CI log, and a
 * private key that has been echoed cannot be un-echoed: anyone holding it can
 * impersonate this server to every subscribed browser. The public half is safe
 * and is shown, because the browser needs it and it is sent to every client
 * anyway. The private half is written only to the environment file the operator
 * names, and is left out of the command's own output entirely.
 */
final class GenerateVapidKeysCommand extends Command
{
    protected $signature = 'push:generate-vapid-keys
                            {--env= : Path to the environment file to update (default: the application env file, not written unless --write is given)}
                            {--write : Write the private key into the environment file, replacing any existing VAPID keys}
                            {--show-private : Print the private key as well. Only ever appropriate on a trusted machine with no shared terminal history.}';

    protected $description = 'Generate the Web Push VAPID key pair used to sign browser notifications.';

    public function handle(): int
    {
        $keys = VAPID::createVapidKeys();

        $public = $keys['publicKey'];
        $private = $keys['privateKey'];

        if ($private === '' || $public === '') {
            $this->error('The VAPID library did not return a usable key pair.');

            return self::FAILURE;
        }

        if ($this->option('write')) {
            $path = $this->envPath();

            if ($path === null) {
                $this->error('Could not resolve an environment file. Pass one with --env=.');

                return self::FAILURE;
            }

            if (! $this->writeKeys($path, $public, $private)) {
                return self::FAILURE;
            }

            $this->info("VAPID keys written to {$path}.");
            $this->line('  The existing keys were replaced. Devices that already subscribed keep working:');
            $this->line('  the endpoint they hold is their own, and the server holds the matching private key.');

            // Never echoed: see the class docblock.
            $this->output->writeln('  <fg=gray>La clave privada no se imprime. Comprueba el archivo si la necesitas.</>', OutputInterface::VERBOSITY_NORMAL);
        } else {
            $this->warn('Nothing was written. Re-run with --write to store the pair in an environment file.');
        }

        $this->line('');
        $this->line('  VAPID_PUBLIC_KEY='.$public);
        $this->line('  VAPID_PRIVATE_KEY='.($this->option('show-private') ? $private : '<not shown; pass --show-private to display>'));
        $this->line('  VAPID_SUBJECT=mailto:'.($this->ask('Contact address for push expiry notices') ?? 'soporte@consultora-dh.local'));

        return self::SUCCESS;
    }

    /**
     * The environment file to update.
     *
     * Only an explicitly named file is ever written. Guessing `.env` from a
     * production shell would overwrite real credentials with fresh keys, and
     * doing it silently is how that goes unnoticed.
     */
    private function envPath(): ?string
    {
        $path = $this->option('env');

        if (! is_string($path) || $path === '') {
            $this->error('Refusing to guess which environment file to write. Pass --env=.env');

            return null;
        }

        if (! is_file($path) || ! is_writable($path)) {
            $this->error("The environment file '{$path}' does not exist or is not writable.");

            return null;
        }

        return $path;
    }

    /**
     * Replace or append the three VAPID lines, preserving everything else.
     *
     * Done by line rather than by rewriting the file so that a `.env` carrying
     * comments, ordering and blank lines comes out recognisably the same file.
     */
    private function writeKeys(string $path, string $public, string $private): bool
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            $this->error("Could not read '{$path}'.");

            return false;
        }

        $replacements = [
            'VAPID_PUBLIC_KEY' => $public,
            'VAPID_PRIVATE_KEY' => $private,
        ];

        $seen = [];

        $lines = array_map(function (string $line) use ($replacements, &$seen): string {
            foreach ($replacements as $key => $value) {
                if (str_starts_with($line, $key.'=')) {
                    $seen[$key] = true;

                    return $key.'='.$value;
                }
            }

            return $line;
        }, explode("\n", $contents));

        $appended = [];

        foreach ($replacements as $key => $value) {
            if (! isset($seen[$key])) {
                $appended[] = $key.'='.$value;
            }
        }

        if ($appended !== []) {
            // Keep the subject adjacent to the keys it belongs with.
            $lines = array_merge($lines, $appended);
        }

        if (file_put_contents($path, implode("\n", $lines), LOCK_EX) === false) {
            $this->error("Could not write '{$path}'.");

            return false;
        }

        return true;
    }
}
