<?php

namespace App\Console\Concerns;

use App\Enums\SyncRunStatus;
use App\Models\SyncRun;
use Closure;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Records a command run in `sync_runs` and copies everything the command prints to the run's log file.
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
        $this->syncRun = $this->startSyncRun($options);

        try {
            $exitCode = $body();
        } catch (Throwable $exception) {
            $this->finishSyncRun(SyncRunStatus::Failed, $exception->getMessage());

            throw $exception;
        }

        $this->finishSyncRun($exitCode === self::SUCCESS ? SyncRunStatus::Succeeded : SyncRunStatus::Failed, $this->syncRunMessage);

        return $exitCode;
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
     * @param  array<string, mixed>  $options
     */
    private function startSyncRun(array $options): SyncRun
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
