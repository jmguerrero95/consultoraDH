<?php

declare(strict_types=1);

it('runs backup and restore in a PostgreSQL 18 sidecar, not the PHP image', function (): void {
    $dockerfile = (string) file_get_contents(base_path('docker/backup/Dockerfile'));
    $compose = (string) file_get_contents(base_path('compose.yaml'));

    expect($dockerfile)
        ->toContain('FROM postgres:18.6')
        ->toContain('gnupg')
        ->toContain('/usr/local/bin/backup-run');

    expect($compose)
        ->toContain('profiles: ["ops"]')
        ->toContain('image: consultora-dh/backup:local')
        ->toContain('BACKUP_REQUIRE_ENCRYPTION')
        ->toContain('${BACKUP_HOST_DIR:-./backups}:/backups')
        ->not->toContain('postgresql-client');
});

it('creates a dedicated backup role with CREATEDB and read-only data access', function (): void {
    $init = (string) file_get_contents(base_path('docker/postgres/init/02-create-backup-role.sh'));

    expect($init)
        ->toContain('CREATEDB')
        ->toContain('NOSUPERUSER')
        ->toContain('NOCREATEROLE')
        ->toContain('pg_read_all_data')
        ->not->toContain('SUPERUSER PASSWORD');
});

it('backs up counts and encrypts before writing the manifest', function (): void {
    $backup = (string) file_get_contents(base_path('scripts/backup.sh'));

    expect($backup)
        ->toContain('pg_dump')
        ->toContain('COUNTS.tsv')
        ->toContain('BACKUP_REQUIRE_ENCRYPTION')
        ->toContain('--symmetric --cipher-algo AES256')
        ->toContain('.last-success')
        ->toContain('MANIFEST.sha256')
        ->toContain('BACKUP_OFFSITE_DIR');
});

it('restores into a disposable database and compares counts and files', function (): void {
    $restore = (string) file_get_contents(base_path('scripts/restore_proof.sh'));

    expect($restore)
        ->toContain('DISPOSABLE_DB="consultora_dh_restore_')
        ->toContain('--maintenance-db=postgres')
        ->toContain('--single-transaction')
        ->toContain('COUNTS.tsv')
        ->toContain('StorageFiles')
        ->toContain('dropdb')
        ->toContain('trap cleanup EXIT')
        ->not->toContain('pg_restore --clean');
});

it('fails the backup health check when no recent success exists', function (): void {
    $dir = sys_get_temp_dir().'/consultora-backup-health-'.uniqid();
    mkdir($dir, 0700);

    try {
        $script = base_path('docker/backup/healthcheck.sh');

        exec('BACKUP_DIR='.escapeshellarg($dir).' BACKUP_MAX_AGE_HOURS=36 bash '.escapeshellarg($script).' 2>&1', $output, $status);
        expect($status)->toBe(1)
            ->and(implode("\n", $output))->toContain('no successful backup marker');

        file_put_contents($dir.'/.last-success', (string) now()->getTimestamp());
        $output = [];
        exec('BACKUP_DIR='.escapeshellarg($dir).' BACKUP_MAX_AGE_HOURS=36 bash '.escapeshellarg($script).' 2>&1', $output, $status);
        expect($status)->toBe(0);

        file_put_contents($dir.'/.last-success', (string) (now()->getTimestamp() - 37 * 3600));
        $output = [];
        exec('BACKUP_DIR='.escapeshellarg($dir).' BACKUP_MAX_AGE_HOURS=36 bash '.escapeshellarg($script).' 2>&1', $output, $status);
        expect($status)->toBe(1)
            ->and(implode("\n", $output))->toContain('last successful backup');
    } finally {
        @unlink($dir.'/.last-success');
        @rmdir($dir);
    }
});
