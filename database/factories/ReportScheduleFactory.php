<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Reports\ReportCadence;
use App\Domain\Reports\ReportFormat;
use App\Models\ReportSchedule;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReportSchedule>
 */
final class ReportScheduleFactory extends Factory
{
    protected $model = ReportSchedule::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => $this->faker->sentence(3),
            'report_type' => 'morosos',
            'filters' => [],
            'format' => ReportFormat::Pdf,
            'cadence' => ReportCadence::Weekly,
            'run_time' => '08:00:00',
            'day_of_week' => 1,
            'day_of_month' => null,
            'owner_user_id' => User::factory(),
            'active' => true,
            'next_run_at' => now()->addWeek(),
            'last_run_at' => null,
        ];
    }
}
