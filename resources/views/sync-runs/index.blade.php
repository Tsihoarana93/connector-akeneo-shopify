<x-layouts.app title="Syncs" :refresh="$activeCommands !== [] ? 10 : null">
    <h1 class="text-2xl font-semibold">Sync history</h1>

    <section class="grid gap-4 md:grid-cols-2">
        @foreach (\App\Models\SyncRun::COMMANDS as $shortName => $commandName)
            @php($isActive = in_array($commandName, $activeCommands, true))
            <div class="flex flex-col gap-3 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <div class="flex items-baseline justify-between gap-2">
                    <h2 class="font-semibold">{{ ucfirst($shortName) }} <code class="text-xs text-gray-500">{{ $commandName }}</code></h2>
                    @if ($isActive)
                        <x-sync-run-status :status="\App\Enums\SyncRunStatus::Running" />
                    @endif
                </div>
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    Last success:
                    @if ($lastSucceeded[$shortName])
                        <a href="{{ route('sync-runs.show', $lastSucceeded[$shortName]) }}" class="underline" title="{{ $lastSucceeded[$shortName]->finished_at }}">{{ $lastSucceeded[$shortName]->finished_at->diffForHumans() }}</a>
                    @else
                        never
                    @endif
                </p>

                <form method="POST" action="{{ route('sync-runs.store') }}" class="flex flex-wrap items-center gap-3 text-sm">
                    @csrf
                    <input type="hidden" name="command" value="{{ $shortName }}">
                    @if ($shortName === 'sync')
                        <label class="flex items-center gap-1"><input type="checkbox" name="prune" value="1"> Prune</label>
                    @else
                        <label class="flex items-center gap-1"><input type="checkbox" name="force" value="1"> Force</label>
                        <label class="flex items-center gap-1">Limit
                            <input type="number" name="limit" min="1" class="w-20 rounded-md border border-gray-300 px-2 py-1 dark:border-gray-700 dark:bg-gray-800">
                        </label>
                    @endif
                    <button type="submit" @disabled($isActive)
                            class="ml-auto rounded-md bg-gray-900 px-3 py-1.5 font-medium text-white hover:bg-gray-700 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-white dark:text-gray-900 dark:hover:bg-gray-200">
                        Run {{ $shortName }} now
                    </button>
                </form>
            </div>
        @endforeach
    </section>

    @if ($errors->any())
        <div class="rounded-md bg-red-50 px-4 py-3 text-sm text-red-800 dark:bg-red-950 dark:text-red-200">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <form method="GET" action="{{ route('sync-runs.index') }}" class="flex flex-wrap items-center gap-3 text-sm">
        <select name="command" class="rounded-md border border-gray-300 px-2 py-1.5 dark:border-gray-700 dark:bg-gray-800">
            <option value="">All commands</option>
            @foreach (array_keys(\App\Models\SyncRun::COMMANDS) as $shortName)
                <option value="{{ $shortName }}" @selected($command === $shortName)>{{ ucfirst($shortName) }}</option>
            @endforeach
        </select>
        <select name="status" class="rounded-md border border-gray-300 px-2 py-1.5 dark:border-gray-700 dark:bg-gray-800">
            <option value="">All statuses</option>
            @foreach (\App\Enums\SyncRunStatus::cases() as $case)
                <option value="{{ $case->value }}" @selected($status === $case)>{{ $case->label() }}</option>
            @endforeach
        </select>
        <button type="submit" class="rounded-md border border-gray-300 px-3 py-1.5 hover:bg-gray-100 dark:border-gray-700 dark:hover:bg-gray-800">Filter</button>
    </form>

    <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
        <table class="w-full text-left text-sm">
            <thead class="border-b border-gray-200 text-xs uppercase text-gray-500 dark:border-gray-800">
                <tr>
                    <th class="px-4 py-2">#</th>
                    <th class="px-4 py-2">Command</th>
                    <th class="px-4 py-2">Status</th>
                    <th class="px-4 py-2">Started</th>
                    <th class="px-4 py-2">Duration</th>
                    <th class="px-4 py-2">Counts</th>
                    <th class="px-4 py-2">By</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                @forelse ($runs as $run)
                    <tr>
                        <td class="px-4 py-2 text-gray-500">{{ $run->id }}</td>
                        <td class="px-4 py-2">{{ ucfirst($run->shortCommand()) }}</td>
                        <td class="px-4 py-2"><x-sync-run-status :status="$run->status" /></td>
                        <td class="px-4 py-2 whitespace-nowrap">{{ ($run->started_at ?? $run->queued_at ?? $run->created_at)->format('Y-m-d H:i') }}</td>
                        <td class="px-4 py-2">{{ $run->durationInSeconds() !== null ? gmdate('H:i:s', $run->durationInSeconds()) : '—' }}</td>
                        <td class="px-4 py-2 text-xs text-gray-600 dark:text-gray-400">
                            {{ collect($run->counts ?? [])->map(fn ($count, $name) => str_replace('_', ' ', $name).': '.$count)->implode(', ') ?: '—' }}
                        </td>
                        <td class="px-4 py-2 text-gray-600 dark:text-gray-400">{{ $run->user?->email ?? 'CLI / scheduler' }}</td>
                        <td class="px-4 py-2 text-right"><a href="{{ route('sync-runs.show', $run) }}" class="underline">Details</a></td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-6 text-center text-gray-500">No runs recorded yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $runs->links() }}
</x-layouts.app>
