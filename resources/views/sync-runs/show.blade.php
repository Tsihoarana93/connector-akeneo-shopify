<x-layouts.app :title="'Run #'.$run->id" :refresh="$run->status->isActive() ? 5 : null">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <a href="{{ route('sync-runs.index') }}" class="text-sm text-gray-500 hover:underline">← All runs</a>
            <h1 class="text-2xl font-semibold">{{ ucfirst($run->shortCommand()) }} run #{{ $run->id }}</h1>
            <x-sync-run-status :status="$run->status" />
        </div>
        @if ($run->hasLog())
            <a href="{{ route('sync-runs.log', $run) }}" class="rounded-md bg-gray-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-gray-700 dark:bg-white dark:text-gray-900 dark:hover:bg-gray-200">Download log</a>
        @endif
    </div>

    @if ($run->message)
        <p @class([
            'rounded-md px-4 py-3 text-sm',
            'bg-red-50 text-red-800 dark:bg-red-950 dark:text-red-200' => $run->status === \App\Enums\SyncRunStatus::Failed,
            'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-200' => $run->status !== \App\Enums\SyncRunStatus::Failed,
        ])>{{ $run->message }}</p>
    @endif

    <dl class="grid gap-4 rounded-lg border border-gray-200 bg-white p-4 text-sm sm:grid-cols-2 lg:grid-cols-4 dark:border-gray-800 dark:bg-gray-900">
        <div><dt class="text-gray-500">Command</dt><dd><code>{{ $run->command }}</code></dd></div>
        <div><dt class="text-gray-500">Started by</dt><dd>{{ $run->user?->email ?? 'CLI / scheduler' }}</dd></div>
        <div><dt class="text-gray-500">Queued</dt><dd>{{ $run->queued_at?->format('Y-m-d H:i:s') ?? '—' }}</dd></div>
        <div><dt class="text-gray-500">Started</dt><dd>{{ $run->started_at?->format('Y-m-d H:i:s') ?? '—' }}</dd></div>
        <div><dt class="text-gray-500">Finished</dt><dd>{{ $run->finished_at?->format('Y-m-d H:i:s') ?? '—' }}</dd></div>
        <div><dt class="text-gray-500">Duration</dt><dd>{{ $run->durationInSeconds() !== null ? gmdate('H:i:s', $run->durationInSeconds()) : '—' }}</dd></div>
        <div>
            <dt class="text-gray-500">Options</dt>
            <dd>{{ collect($run->options ?? [])->filter(fn ($value) => $value !== null && $value !== false)->map(fn ($value, $name) => $value === true ? "--{$name}" : "--{$name}={$value}")->implode(' ') ?: 'none' }}</dd>
        </div>
        <div>
            <dt class="text-gray-500">Counts</dt>
            <dd>
                @forelse ($run->counts ?? [] as $name => $count)
                    <span class="block">{{ str_replace('_', ' ', $name) }}: {{ $count }}</span>
                @empty
                    —
                @endforelse
            </dd>
        </div>
    </dl>

    <section class="flex flex-col gap-2">
        <h2 class="font-semibold">
            Log
            @if (count($logLines) === 200)
                <span class="text-sm font-normal text-gray-500">(last 200 lines, download for the full log)</span>
            @endif
        </h2>
        @if ($logLines === [])
            <p class="text-sm text-gray-500">No log yet.</p>
        @else
            <pre class="max-h-[32rem] overflow-auto rounded-lg bg-gray-900 p-4 text-xs leading-relaxed text-gray-100">
@foreach ($logLines as $line)
@php($lineColor = str_contains($line, '] ERROR ') ? 'text-red-300' : (str_contains($line, '] WARNING ') ? 'text-yellow-200' : ''))
<span class="{{ $lineColor }}">{{ $line }}</span>
@endforeach
</pre>
        @endif
    </section>
</x-layouts.app>
