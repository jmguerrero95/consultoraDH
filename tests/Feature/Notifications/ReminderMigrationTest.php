<?php

declare(strict_types=1);

use App\Domain\Operations\TaskStatus;
use App\Models\OperationalTask;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    seedPortfolioRoles();
});

/**
 * The reminder migration reconciles, it does not assume.
 *
 * It was first applied by hand while the flow was being built, so the columns
 * already existed in the operational database. Running it again must add only
 * what is missing and must not touch a value that is already there.
 */
it('has every reminder column the dispatcher writes', function (): void {
    expect(Schema::hasColumns('operational_tasks', [
        'reminder_received_at',
        'reminder_dismissed_at',
        'reminder_second_sent_at',
        'reminder_attempts',
    ]))->toBeTrue();
});

it('can run again on a database that already has the columns without losing values', function (): void {
    $task = OperationalTask::factory()->create([
        'assigned_to' => userWithPermissions(['tasks.view'])->id,
        'status' => TaskStatus::Pending,
        'reminder_at' => now()->subHour(),
        'reminder_sent_at' => now()->subMinutes(40),
        'reminder_received_at' => now()->subMinutes(30),
        'reminder_dismissed_at' => now()->subMinutes(29),
        'reminder_second_sent_at' => now()->subMinutes(20),
        'reminder_attempts' => 2,
    ]);

    $migration = require database_path('migrations/2026_10_24_140000_add_reminder_tracking_to_operational_tasks.php');

    $migration->up();

    expect(Schema::hasColumns('operational_tasks', [
        'reminder_received_at',
        'reminder_dismissed_at',
        'reminder_second_sent_at',
        'reminder_attempts',
    ]))->toBeTrue();

    $task->refresh();

    expect($task->reminder_attempts)->toBe(2)
        ->and($task->reminder_received_at)->not->toBeNull()
        ->and($task->reminder_dismissed_at)->not->toBeNull()
        ->and($task->reminder_second_sent_at)->not->toBeNull();
});
