<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SupportQueueMember;
use Illuminate\Database\Eloquent\Factories\Factory;

class SupportQueueMemberFactory extends Factory
{
    protected $model = SupportQueueMember::class;

    public function definition(): array
    {
        return [
            'queue_id' => null,
            'user_id' => null,
            'created_by' => null,
        ];
    }
}