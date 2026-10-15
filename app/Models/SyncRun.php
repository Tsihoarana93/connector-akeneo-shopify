<?php

namespace App\Models;

use App\Enums\SyncRunStatus;
use Database\Factories\SyncRunFactory;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
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

    /**
     * Held by everything that writes the Akeneo mirror tables: the sync command and the attribute "Update from Akeneo" button.
     */
    public const AKENEO_WRITES_LOCK = 'fidelity:akeneo-writes';

    public const SHOPIFY_PUSH_LOCK = 'fidelity:shopify-push';

    /**
     * The lock each recorded command holds for its whole run, and how long it survives a killed process, in seconds.
     * Both outlive the job's one-hour timeout, and leave room for CLI and scheduled runs, which have no timeout.
     */
    public const LOCKS = [
        'fidelity:sync-products' => [self::AKENEO_WRITES_LOCK, 14400],
        'fidelity:push-products' => [self::SHOPIFY_PUSH_LOCK, 14400],
    ];

    protected $fillable = [
        'command',
        'status',
        'options',
        'counts',
        'message',
        'log_path',
        'lock_owner',
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

    /**
     * A new lock for a command's run.
     */
    public static function lockFor(string $command): Lock
    {
        [$name, $seconds] = self::LOCKS[$command];

        return Cache::lock($name, $seconds);
    }

    /**
     * The lock this run holds, restored from its owner token so it can be released by someone else, such as a failed job.
     */
    public function heldLock(): ?Lock
    {
        if ($this->lock_owner === null || ! isset(self::LOCKS[$this->command])) {
            return null;
        }

        return Cache::restoreLock(self::LOCKS[$this->command][0], $this->lock_owner);
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
