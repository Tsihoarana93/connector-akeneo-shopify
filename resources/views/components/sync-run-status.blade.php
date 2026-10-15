@props(['status'])

@php
    $classes = match ($status) {
        \App\Enums\SyncRunStatus::Queued => 'border-line-strong text-ink-subtle',
        \App\Enums\SyncRunStatus::Running => 'border-sky-400/30 bg-sky-400/5 text-sky-300 before:animate-pulse',
        \App\Enums\SyncRunStatus::Succeeded => 'border-brand/30 bg-brand/5 text-brand',
        \App\Enums\SyncRunStatus::Failed => 'border-red-500/30 bg-red-500/5 text-red-300',
    };
@endphp

<span {{ $attributes->class(['badge', $classes]) }}>{{ $status->label() }}</span>
