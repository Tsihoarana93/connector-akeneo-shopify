<?php

namespace Database\Factories;

use App\Enums\SyncRunStatus;
use App\Models\SyncRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SyncRun>
 */
class SyncRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startedAt = now()->subMinutes(fake()->numberBetween(5, 600));

        return [
            'command' => SyncRun::COMMANDS['sync'],
            'status' => SyncRunStatus::Succeeded,
            'options' => [],
            'counts' => ['product_models' => 2, 'products' => 10, 'pruned_product_models' => 0, 'pruned_products' => 0],
            'message' => null,
            'log_path' => null,
            'user_id' => null,
            'queued_at' => null,
            'started_at' => $startedAt,
            'finished_at' => $startedAt->copy()->addSeconds(fake()->numberBetween(1, 120)),
        ];
    }

    /**
     * Indicate that the run is a push.
     */
    public function push(): static
    {
        return $this->state(fn (array $attributes): array => [
            'command' => SyncRun::COMMANDS['push'],
            'counts' => ['created' => 1, 'updated' => 2, 'skipped' => 3, 'failed' => 0],
        ]);
    }

    /**
     * Indicate that the run was queued from the interface and has not started.
     */
    public function queued(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => SyncRunStatus::Queued,
            'counts' => null,
            'queued_at' => now(),
            'started_at' => null,
            'finished_at' => null,
        ]);
    }

    /**
     * Indicate that the run is still running.
     */
    public function running(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => SyncRunStatus::Running,
            'counts' => null,
            'started_at' => now()->subMinute(),
            'finished_at' => null,
        ]);
    }

    /**
     * Indicate that the run failed.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => SyncRunStatus::Failed,
            'message' => 'Sync aborted: Akeneo is unreachable.',
        ]);
    }
}
