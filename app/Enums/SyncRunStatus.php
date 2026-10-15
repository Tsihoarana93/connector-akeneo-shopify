<?php

namespace App\Enums;

enum SyncRunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    /**
     * Whether a run in this status has not finished yet.
     */
    public function isActive(): bool
    {
        return $this === self::Queued || $this === self::Running;
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
