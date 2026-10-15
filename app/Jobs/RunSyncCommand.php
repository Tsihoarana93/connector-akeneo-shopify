<?php

namespace App\Jobs;

use App\Enums\SyncRunStatus;
use App\Models\SyncRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * Run a sync or push command started from the interface, recording into its queued sync run.
 */
class RunSyncCommand implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * Keep below the queue's `retry_after` (DB_QUEUE_RETRY_AFTER), or the job is started a second time while it runs.
     */
    public int $timeout = 3600;

    public bool $failOnTimeout = true;

    public function __construct(public SyncRun $syncRun) {}

    public function handle(): void
    {
        $options = $this->syncRun->options ?? [];

        Artisan::call($this->syncRun->command, array_filter([
            '--prune' => $options['prune'] ?? false,
            '--force' => $options['force'] ?? false,
            '--limit' => $options['limit'] ?? null,
            '--sync-run' => $this->syncRun->id,
        ], fn (mixed $value): bool => $value !== false && $value !== null));
    }

    /**
     * Mark the run as failed when the job died before the command could record it.
     */
    public function failed(?Throwable $exception): void
    {
        $this->syncRun->refresh();

        if (! $this->syncRun->status->isActive()) {
            return;
        }

        $this->syncRun->update([
            'status' => SyncRunStatus::Failed,
            'message' => $exception?->getMessage() ?: 'The queued job failed.',
            'finished_at' => now(),
        ]);
    }
}
