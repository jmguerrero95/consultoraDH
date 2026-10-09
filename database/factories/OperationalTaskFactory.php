<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Operations\TaskPriority;
use App\Domain\Operations\TaskStatus;
use App\Models\Client;
use App\Models\OperationalTask;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OperationalTask>
 */
final class OperationalTaskFactory extends Factory
{
    protected $model = OperationalTask::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'novelty_id' => null,
            'document_request_id' => null,
            'contribution_sheet_id' => null,
            'title' => $this->faker->sentence(5),
            'description' => $this->faker->paragraph(),
            'assigned_to' => User::factory(),
            'created_by' => User::factory(),
            'priority' => TaskPriority::Normal,
            'status' => TaskStatus::Pending,
            'due_on' => now()->addWeek()->toDateString(),
            'reminder_at' => null,
            'reminder_sent_at' => null,
            'completed_at' => null,
            'completed_by' => null,
            'cancelled_at' => null,
        ];
    }
}
