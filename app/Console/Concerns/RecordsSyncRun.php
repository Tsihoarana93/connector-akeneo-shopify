<?php

namespace App\Console\Concerns;

use App\Enums\SyncRunStatus;
use App\Models\SyncRun;
use Closure;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Records a command run in `sync_runs` and copies everything the command prints to the run's log file.
 * The run holds its command's lock (`SyncRun::LOCKS`) from start to finish, so two runs never write the same data at once.
 * The command must define a `{--sync-run=}` option.
 */
trait RecordsSyncRun
{
    protected ?SyncRun $syncRun = null;

    /**
     * Counts stored on the run when it finishes.
     *
     * @var array<string, int>
     */
    protected array $syncRunCounts = [];

    /**
     * Message stored on the run when it finishes, such as the reason it was aborted.
     */
    protected ?string $syncRunMessage = null;

    /**
     * Run the command body inside a recorded sync run and return its exit code.
     *
     * @param  array<string, mixed>  $options
     * @param  Closure(): int  $body
     */
    protected function recordSyncRun(array $options, Closure $body): int
    {
        $lock = SyncRun::lockFor((string) $this->getName());

        if (! $lock->get()) {
            return $this->refuseSyncRun();
        }

        try {
            $this->syncRun = $this->startSyncRun($options, $lock->owner());

            try {
                $exitCode = $body();
            } catch (Throwable $exception) {
                $this->finishSyncRun(SyncRunStatus::Failed, $exception->getMessage());

                throw $exception;
            }

            $this->finishSyncRun($exitCode === self::SUCCESS ? SyncRunStatus::Succeeded : SyncRunStatus::Failed, $this->syncRunMessage);

            return $exitCode;
        } finally {
            $lock->release();
        }
    }

    /**
     * Don't start while another run holds the lock. A queued run claimed from the interface is closed with the reason;
     * a refused CLI or scheduler run records nothing, so a long run doesn't leave a trail of failed rows behind it.
     */
    private function refuseSyncRun(): int
    {
        $command = (string) $this->getName();
        $running = SyncRun::query()->where('command', $command)->where('status', SyncRunStatus::Running)->latest('id')->first();
        $message = 'Not started: another run holds the '.SyncRun::LOCKS[$command][0].' lock. Try again when it finishes.'
            .($running !== null ? " The latest {$command} run still marked as running is #{$running->id}." : '');

        $this->error($message);

        if (filled($this->option('sync-run'))) {
            SyncRun::query()->where('command', $command)->whereKey($this->option('sync-run'))->update([
                'status' => SyncRunStatus::Failed,
                'message' => $message,
                'finished_at' => now(),
            ]);
        }

        return self::FAILURE;
    }

    /**
     * Write a line to the console and append it to the run's log file.
     *
     * @param  string  $string
     * @param  string|null  $style
     * @param  int|string|null  $verbosity
     */
    public function line($string, $style = null, $verbosity = null): void
    {
        parent::line($string, $style, $verbosity);

        $this->appendToSyncRunLog(match ($style) {
            'error' => 'ERROR',
            'warning' => 'WARNING',
            default => 'INFO',
        }, (string) $string);
    }

    /**
     * Start the run. It holds the lock, so any other run still marked as running belongs to a process that died.
     *
     * @param  array<string, mixed>  $options
     */
    private function startSyncRun(array $options, string $lockOwner): SyncRun
    {
        $command = (string) $this->getName();

        SyncRun::query()
            ->where('command', $command)
            ->where('status', SyncRunStatus::Running)
            ->update([
                'status' => SyncRunStatus::Failed,
                'message' => 'Interrupted before it finished.',
                'finished_at' => now(),
            ]);

        $run = filled($this->option('sync-run'))
            ? SyncRun::query()->where('command', $command)->find($this->option('sync-run'))
            : null;

        $run ??= new SyncRun(['command' => $command]);
        $run->fill([
            'status' => SyncRunStatus::Running,
            'options' => $options,
            'lock_owner' => $lockOwner,
            'started_at' => now(),
        ])->save();

        Storage::disk(SyncRun::LOG_DISK)->makeDirectory('sync-runs');
        $run->update(['log_path' => "sync-runs/{$run->id}.log"]);

        return $run;
    }

    private function finishSyncRun(SyncRunStatus $status, ?string $message): void
    {
        $counts = collect($this->syncRunCounts)->map(fn (int $count, string $name): string => "{$name}={$count}")->implode(', ');
        $this->appendToSyncRunLog($status === SyncRunStatus::Succeeded ? 'INFO' : 'ERROR', "Finished: {$status->value}".($counts !== '' ? " ({$counts})" : ''));

        $this->syncRun->update([
            'status' => $status,
            'counts' => $this->syncRunCounts,
            'message' => $message,
            'lock_owner' => null,
            'finished_at' => now(),
        ]);
    }

    private function appendToSyncRunLog(string $level, string $message): void
    {
        if ($this->syncRun?->log_path === null) {
            return;
        }

        file_put_contents(
            Storage::disk(SyncRun::LOG_DISK)->path($this->syncRun->log_path),
            '['.now()->format('Y-m-d H:i:s')."] {$level} {$message}".PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }
}
