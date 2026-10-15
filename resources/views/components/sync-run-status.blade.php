@props(['status'])

@php
    $classes = match ($status) {
        \App\Enums\SyncRunStatus::Queued => 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300',
        \App\Enums\SyncRunStatus::Running => 'bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-200',
        \App\Enums\SyncRunStatus::Succeeded => 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-200',
        \App\Enums\SyncRunStatus::Failed => 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200',
    };
@endphp

<span {{ $attributes->class(['inline-flex rounded-full px-2 py-0.5 text-xs font-medium', $classes]) }}>{{ $status->label() }}</span>
