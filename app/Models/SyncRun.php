<?php

namespace App\Models;

use App\Enums\SyncRunStatus;
use Database\Factories\SyncRunFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class SyncRun extends Model
{
    /** @use HasFactory<SyncRunFactory> */
    use HasFactory;

    /**
     * Commands that are recorded and can be started from the interface, keyed by their short name.
     */
    public const COMMANDS = [
        'sync' => 'fidelity:sync-products',
        'push' => 'fidelity:push-products',
    ];

    public const LOG_DISK = 'local';

    protected $fillable = [
        'command',
        'status',
        'options',
        'counts',
        'message',
        'log_path',
        'user_id',
        'queued_at',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => SyncRunStatus::class,
            'options' => 'array',
            'counts' => 'array',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * The user who started the run from the interface; null for the CLI and the scheduler.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Runs that are queued or running.
     *
     * @param  Builder<SyncRun>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', [SyncRunStatus::Queued, SyncRunStatus::Running]);
    }

    public function shortCommand(): string
    {
        return array_search($this->command, self::COMMANDS, true) ?: $this->command;
    }

    public function durationInSeconds(): ?int
    {
        if ($this->started_at === null) {
            return null;
        }

        return (int) $this->started_at->diffInSeconds($this->finished_at ?? now());
    }

    public function hasLog(): bool
    {
        return $this->log_path !== null && Storage::disk(self::LOG_DISK)->exists($this->log_path);
    }

    /**
     * The last lines of the log file, oldest first.
     *
     * @return list<string>
     */
    public function logTail(int $lines = 200): array
    {
        if (! $this->hasLog()) {
            return [];
        }

        $content = rtrim((string) Storage::disk(self::LOG_DISK)->get($this->log_path), PHP_EOL);

        return $content === '' ? [] : array_slice(explode(PHP_EOL, $content), -$lines);
    }
}
