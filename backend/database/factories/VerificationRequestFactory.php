<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Trust\Enums\VerificationStatus;
use App\Domain\Trust\Enums\VerificationType;
use App\Models\User;
use App\Models\VerificationRequest;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<VerificationRequest> */
class VerificationRequestFactory extends Factory
{
    protected $model = VerificationRequest::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid7(),
            'user_id' => User::factory()->seller(),
            'type' => VerificationType::NationalId,
            // `status` is guarded, so it is set here rather than through fill —
            // the API must never let a client choose its own review outcome.
            'status' => VerificationStatus::Pending,
            'document_number' => $this->faker->numerify('###########'),
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'status' => VerificationStatus::Approved,
            'reviewed_at' => now()->subDay(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn () => [
            'status' => VerificationStatus::Rejected,
            'reviewed_at' => now()->subDay(),
            'rejection_reason' => 'The document was unreadable.',
        ]);
    }

    public function ofType(VerificationType $type): static
    {
        return $this->state(fn () => ['type' => $type]);
    }
}
