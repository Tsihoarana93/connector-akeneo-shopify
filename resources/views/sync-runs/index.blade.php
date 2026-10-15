<x-layouts.app title="Syncs" :refresh="$activeCommands !== [] ? 10 : null">
    <div class="flex flex-col gap-2">
        <span class="label-mono">Akeneo → Shopify</span>
        <h1 class="page-title">Sync history</h1>
    </div>

    <section class="grid gap-4 md:grid-cols-2">
        @foreach (\App\Models\SyncRun::COMMANDS as $shortName => $commandName)
            @php($isActive = in_array($commandName, $activeCommands, true))
            <div class="card flex flex-col gap-4 p-5">
                <div class="flex items-start justify-between gap-2">
                    <div class="flex flex-col gap-1">
                        <h2 class="text-lg font-medium">{{ ucfirst($shortName) }}</h2>
                        <code class="font-mono text-xs text-ink-subtle">{{ $commandName }}</code>
                    </div>
                    @if ($isActive)
                        <x-sync-run-status :status="\App\Enums\SyncRunStatus::Running" />
                    @endif
                </div>
                <p class="text-sm text-ink-muted">
                    Last success:
                    @if ($lastSucceeded[$shortName])
                        <a href="{{ route('sync-runs.show', $lastSucceeded[$shortName]) }}" class="link" title="{{ $lastSucceeded[$shortName]->finished_at }}">{{ $lastSucceeded[$shortName]->finished_at->diffForHumans() }}</a>
                    @else
                        never
                    @endif
                </p>

                <form method="POST" action="{{ route('sync-runs.store') }}" class="flex flex-wrap items-center gap-4 border-t border-line pt-4 text-sm text-ink-muted">
                    @csrf
                    <input type="hidden" name="command" value="{{ $shortName }}">
                    @if ($shortName === 'sync')
                        <label class="flex items-center gap-2"><input type="checkbox" name="prune" value="1" class="checkbox"> Prune</label>
                    @else
                        <label class="flex items-center gap-2"><input type="checkbox" name="force" value="1" class="checkbox"> Force</label>
                        <label class="flex items-center gap-2">Limit
                            <input type="number" name="limit" min="1" class="field w-20">
                        </label>
                    @endif
                    <button type="submit" @disabled($isActive) class="btn-primary ml-auto">Run {{ $shortName }} now</button>
                </form>
            </div>
        @endforeach
    </section>

    @if ($errors->any())
        <div class="alert-error">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <section class="flex flex-col gap-4">
        <form method="GET" action="{{ route('sync-runs.index') }}" class="flex flex-wrap items-center gap-3">
            <select name="command" class="field">
                <option value="">All commands</option>
                @foreach (array_keys(\App\Models\SyncRun::COMMANDS) as $shortName)
                    <option value="{{ $shortName }}" @selected($command === $shortName)>{{ ucfirst($shortName) }}</option>
                @endforeach
            </select>
            <select name="status" class="field">
                <option value="">All statuses</option>
                @foreach (\App\Enums\SyncRunStatus::cases() as $case)
                    <option value="{{ $case->value }}" @selected($status === $case)>{{ $case->label() }}</option>
                @endforeach
            </select>
            <button type="submit" class="btn-secondary">Filter</button>
        </form>

        <div class="card overflow-x-auto">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Command</th>
                        <th>Status</th>
                        <th>Started</th>
                        <th>Duration</th>
                        <th>Counts</th>
                        <th>By</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($runs as $run)
                        <tr>
                            <td class="font-mono text-xs text-ink-subtle">{{ $run->id }}</td>
                            <td>{{ ucfirst($run->shortCommand()) }}</td>
                            <td><x-sync-run-status :status="$run->status" /></td>
                            <td class="font-mono text-xs whitespace-nowrap text-ink-muted">{{ ($run->started_at ?? $run->queued_at ?? $run->created_at)->format('Y-m-d H:i') }}</td>
                            <td class="font-mono text-xs text-ink-muted">{{ $run->durationInSeconds() !== null ? gmdate('H:i:s', $run->durationInSeconds()) : '—' }}</td>
                            <td class="text-xs text-ink-muted">
                                {{ collect($run->counts ?? [])->map(fn ($count, $name) => str_replace('_', ' ', $name).': '.$count)->implode(', ') ?: '—' }}
                            </td>
                            <td class="text-ink-muted">{{ $run->user?->email ?? 'CLI / scheduler' }}</td>
                            <td class="text-right"><a href="{{ route('sync-runs.show', $run) }}" class="link font-mono text-xs uppercase">Details</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="py-10 text-center text-ink-subtle">No runs recorded yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $runs->links() }}
    </section>
</x-layouts.app>
