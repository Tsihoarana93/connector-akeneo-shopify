<?php

namespace Tests\Feature;

use App\Enums\SyncRunStatus;
use App\Jobs\RunSyncCommand;
use App\Models\SyncRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SyncRunControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake('local');
        Queue::fake();

        $this->user = User::factory()->create();
    }

    public function test_the_index_lists_runs_newest_first(): void
    {
        $older = SyncRun::factory()->create(['created_at' => now()->subHour()]);
        $newer = SyncRun::factory()->push()->failed()->create();

        $this->actingAs($this->user)
            ->get(route('sync-runs.index'))
            ->assertOk()
            ->assertSeeInOrder([route('sync-runs.show', $newer), route('sync-runs.show', $older)]);
    }

    public function test_the_index_filters_by_command_and_status(): void
    {
        $failedSync = SyncRun::factory()->failed()->create();
        SyncRun::factory()->create();
        SyncRun::factory()->push()->failed()->create();

        $this->actingAs($this->user)
            ->get(route('sync-runs.index', ['command' => 'sync', 'status' => 'failed']))
            ->assertOk()
            ->assertViewHas('runs', fn ($runs): bool => $runs->pluck('id')->all() === [$failedSync->id]);
    }

    public function test_the_show_page_displays_the_run_and_its_log(): void
    {
        Storage::disk('local')->put('sync-runs/1.log', "[2026-10-14 10:00:00] ERROR Sync aborted: boom\n");
        $run = SyncRun::factory()->failed()->create(['log_path' => 'sync-runs/1.log', 'message' => 'Sync aborted: boom']);

        $this->actingAs($this->user)
            ->get(route('sync-runs.show', $run))
            ->assertOk()
            ->assertSee('Sync aborted: boom')
            ->assertSee('[2026-10-14 10:00:00] ERROR Sync aborted: boom')
            ->assertSee(route('sync-runs.log', $run));
    }

    public function test_the_log_can_be_downloaded(): void
    {
        Storage::disk('local')->put('sync-runs/7.log', 'log content');
        $run = SyncRun::factory()->create(['log_path' => 'sync-runs/7.log']);

        $response = $this->actingAs($this->user)->get(route('sync-runs.log', $run));

        $response->assertDownload("sync-run-{$run->id}.log");
        $this->assertSame('log content', $response->streamedContent());
    }

    public function test_downloading_a_missing_log_returns_not_found(): void
    {
        $run = SyncRun::factory()->create(['log_path' => 'sync-runs/missing.log']);

        $this->actingAs($this->user)->get(route('sync-runs.log', $run))->assertNotFound();
    }

    public function test_guests_cannot_download_a_log(): void
    {
        $run = SyncRun::factory()->create();

        $this->get(route('sync-runs.log', $run))->assertRedirect(route('login'));
    }

    public function test_starting_a_push_queues_a_run_for_the_user(): void
    {
        $response = $this->actingAs($this->user)
            ->post(route('sync-runs.store'), ['command' => 'push', 'force' => '1', 'limit' => '5', 'prune' => '1']);

        $run = SyncRun::query()->sole();
        $response->assertRedirect(route('sync-runs.show', $run));
        $this->assertSame('fidelity:push-products', $run->command);
        $this->assertSame(SyncRunStatus::Queued, $run->status);
        $this->assertEquals(['force' => true, 'limit' => 5], $run->options);
        $this->assertTrue($run->user->is($this->user));
        Queue::assertPushed(RunSyncCommand::class, fn (RunSyncCommand $job): bool => $job->syncRun->is($run));
    }

    public function test_starting_a_command_is_refused_while_one_is_active(): void
    {
        SyncRun::factory()->running()->create();

        $this->actingAs($this->user)
            ->from(route('sync-runs.index'))
            ->post(route('sync-runs.store'), ['command' => 'sync'])
            ->assertRedirect(route('sync-runs.index'))
            ->assertSessionHasErrors(['command' => 'A sync is already queued or running.']);

        $this->assertSame(1, SyncRun::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_starting_a_run_validates_the_input(): void
    {
        $this->actingAs($this->user)
            ->post(route('sync-runs.store'), ['command' => 'delete-everything'])
            ->assertSessionHasErrors('command');

        $this->actingAs($this->user)
            ->post(route('sync-runs.store'), ['command' => 'push', 'limit' => '0'])
            ->assertSessionHasErrors('limit');

        $this->assertSame(0, SyncRun::query()->count());
        Queue::assertNothingPushed();
    }
}
