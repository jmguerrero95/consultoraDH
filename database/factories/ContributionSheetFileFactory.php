<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ContributionSheet;
use App\Models\ContributionSheetFile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContributionSheetFile>
 */
final class ContributionSheetFileFactory extends Factory
{
    protected $model = ContributionSheetFile::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'contribution_sheet_id' => ContributionSheet::factory(),
            'kind' => 'operator_pdf',
            'original_name' => $this->faker->word().'.pdf',
            'stored_path' => 'sheets/'.$this->faker->uuid().'.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 2048,
            'sha256' => $this->faker->sha256(),
            'uploaded_by' => User::factory(),
        ];
    }
}
