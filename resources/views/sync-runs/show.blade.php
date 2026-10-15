<x-layouts.app :title="'Run #'.$run->id" :refresh="$run->status->isActive() ? 5 : null">
    <div class="flex flex-col gap-3">
        <a href="{{ route('sync-runs.index') }}" class="label-mono transition-colors hover:text-brand">← All runs</a>
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="page-title">{{ ucfirst($run->shortCommand()) }} run #{{ $run->id }}</h1>
                <x-sync-run-status :status="$run->status" />
            </div>
            @if ($run->hasLog())
                <a href="{{ route('sync-runs.log', $run) }}" class="btn-secondary">Download log</a>
            @endif
        </div>
    </div>

    @if ($run->message)
        <p @class([
            'alert-error' => $run->status === \App\Enums\SyncRunStatus::Failed,
            'card px-4 py-3 text-sm text-ink-muted' => $run->status !== \App\Enums\SyncRunStatus::Failed,
        ])>{{ $run->message }}</p>
    @endif

    <dl class="card grid gap-6 p-5 text-sm sm:grid-cols-2 lg:grid-cols-4">
        <div class="flex flex-col gap-1"><dt class="label-mono">Command</dt><dd><code class="font-mono text-xs">{{ $run->command }}</code></dd></div>
        <div class="flex flex-col gap-1"><dt class="label-mono">Started by</dt><dd>{{ $run->user?->email ?? 'CLI / scheduler' }}</dd></div>
        <div class="flex flex-col gap-1"><dt class="label-mono">Queued</dt><dd class="font-mono text-xs">{{ $run->queued_at?->format('Y-m-d H:i:s') ?? '—' }}</dd></div>
        <div class="flex flex-col gap-1"><dt class="label-mono">Started</dt><dd class="font-mono text-xs">{{ $run->started_at?->format('Y-m-d H:i:s') ?? '—' }}</dd></div>
        <div class="flex flex-col gap-1"><dt class="label-mono">Finished</dt><dd class="font-mono text-xs">{{ $run->finished_at?->format('Y-m-d H:i:s') ?? '—' }}</dd></div>
        <div class="flex flex-col gap-1"><dt class="label-mono">Duration</dt><dd class="font-mono text-xs">{{ $run->durationInSeconds() !== null ? gmdate('H:i:s', $run->durationInSeconds()) : '—' }}</dd></div>
        <div class="flex flex-col gap-1">
            <dt class="label-mono">Options</dt>
            <dd class="font-mono text-xs">{{ collect($run->options ?? [])->filter(fn ($value) => $value !== null && $value !== false)->map(fn ($value, $name) => $value === true ? "--{$name}" : "--{$name}={$value}")->implode(' ') ?: 'none' }}</dd>
        </div>
        <div class="flex flex-col gap-1">
            <dt class="label-mono">Counts</dt>
            <dd>
                @forelse ($run->counts ?? [] as $name => $count)
                    <span class="block">{{ str_replace('_', ' ', $name) }}: <span class="font-mono text-xs">{{ $count }}</span></span>
                @empty
                    —
                @endforelse
            </dd>
        </div>
    </dl>

    <section class="flex flex-col gap-3">
        <h2 class="section-title">
            Log
            @if (count($logLines) === 200)
                <span class="normal-case tracking-normal text-ink-subtle">(last 200 lines, download for the full log)</span>
            @endif
        </h2>
        @if ($logLines === [])
            <p class="text-sm text-ink-subtle">No log yet.</p>
        @else
            <div class="card overflow-hidden">
                <div class="flex items-center gap-1.5 border-b border-line px-4 py-2.5">
                    <span class="size-2 rounded-full bg-red-400/80"></span>
                    <span class="size-2 rounded-full bg-amber-300/80"></span>
                    <span class="size-2 rounded-full bg-brand/80"></span>
                </div>
                <pre class="max-h-[32rem] overflow-auto bg-canvas p-4 font-mono text-xs leading-relaxed text-ink-muted">
@foreach ($logLines as $line)
@php($lineColor = str_contains($line, '] ERROR ') ? 'text-red-300' : (str_contains($line, '] WARNING ') ? 'text-amber-200' : ''))
<span class="{{ $lineColor }}">{{ $line }}</span>
@endforeach
</pre>
            </div>
        @endif
    </section>
</x-layouts.app>
