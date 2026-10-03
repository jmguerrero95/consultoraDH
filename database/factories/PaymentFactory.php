<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Payments\PaymentMethod;
use App\Models\Client;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'amount_cop' => $this->faker->randomElement([80000, 160000, 235000, 300000, 500000]),
            'received_on' => Carbon::now()->subDays($this->faker->numberBetween(0, 40)),
            'method' => PaymentMethod::Cash->value,
            'reference' => null,
            'notes' => null,
            'created_by' => null,
        ];
    }

    public function amount(int $cop): self
    {
        return $this->state(fn (): array => ['amount_cop' => $cop]);
    }

    public function voided(): self
    {
        return $this->state(fn (): array => [
            'voided_at' => Carbon::now(),
            'void_reason' => 'Anulado en la prueba',
        ]);
    }
}
