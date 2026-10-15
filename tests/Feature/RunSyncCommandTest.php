<?php

namespace Tests\Feature;

use App\Enums\SyncRunStatus;
use App\Jobs\RunSyncCommand;
use App\Models\SyncRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Tests\TestCase;

class RunSyncCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_runs_the_command_with_the_recorded_options_and_run_id(): void
    {
        $run = SyncRun::factory()->push()->queued()->create(['options' => ['force' => true, 'limit' => 3]]);

        Artisan::shouldReceive('call')
            ->once()
            ->with('fidelity:push-products', ['--force' => true, '--limit' => 3, '--sync-run' => $run->id]);

        (new RunSyncCommand($run))->handle();
    }

    public function test_a_failed_job_marks_a_run_that_never_started_as_failed(): void
    {
        $run = SyncRun::factory()->queued()->create();

        (new RunSyncCommand($run))->failed(new RuntimeException('Worker timed out.'));

        $this->assertSame(SyncRunStatus::Failed, $run->fresh()->status);
        $this->assertSame('Worker timed out.', $run->fresh()->message);
        $this->assertNotNull($run->fresh()->finished_at);
    }

    public function test_a_failed_job_keeps_the_status_recorded_by_the_command(): void
    {
        $run = SyncRun::factory()->failed()->create();

        (new RunSyncCommand($run))->failed(new RuntimeException('Command threw.'));

        $this->assertSame('Sync aborted: Akeneo is unreachable.', $run->fresh()->message);
    }
}
