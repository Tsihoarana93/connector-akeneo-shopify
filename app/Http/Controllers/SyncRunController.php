<?php

namespace App\Http\Controllers;

use App\Enums\SyncRunStatus;
use App\Http\Requests\StartSyncRunRequest;
use App\Jobs\RunSyncCommand;
use App\Models\SyncRun;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SyncRunController extends Controller
{
    public function index(Request $request): View
    {
        $command = in_array($request->query('command'), array_keys(SyncRun::COMMANDS), true) ? $request->query('command') : null;
        $status = $request->enum('status', SyncRunStatus::class);

        $runs = SyncRun::query()
            ->with('user')
            ->when($command !== null, fn ($query) => $query->where('command', SyncRun::COMMANDS[$command]))
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->latest()
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        $lastSucceeded = collect(SyncRun::COMMANDS)->map(fn (string $name): ?SyncRun => SyncRun::query()
            ->where('command', $name)
            ->where('status', SyncRunStatus::Succeeded)
            ->latest('finished_at')
            ->first());

        return view('sync-runs.index', [
            'runs' => $runs,
            'lastSucceeded' => $lastSucceeded,
            'activeCommands' => SyncRun::query()->active()->distinct()->pluck('command')->all(),
            'command' => $command,
            'status' => $status,
        ]);
    }

    public function show(SyncRun $syncRun): View
    {
        $syncRun->load('user');

        return view('sync-runs.show', [
            'run' => $syncRun,
            'logLines' => $syncRun->logTail(),
        ]);
    }

    public function log(SyncRun $syncRun): StreamedResponse
    {
        abort_unless($syncRun->hasLog(), 404);

        return Storage::disk(SyncRun::LOG_DISK)->download($syncRun->log_path, "sync-run-{$syncRun->id}.log");
    }

    /**
     * Queue a sync or push, unless one of the same command is already queued or running.
     */
    public function store(StartSyncRunRequest $request): RedirectResponse
    {
        $command = $request->command();

        $run = Cache::lock("sync-runs:start:{$command}", 10)->get(function () use ($request, $command): ?SyncRun {
            if (SyncRun::query()->active()->where('command', $command)->exists()) {
                return null;
            }

            return SyncRun::query()->create([
                'command' => $command,
                'status' => SyncRunStatus::Queued,
                'options' => $request->commandOptions(),
                'user_id' => $request->user()->id,
                'queued_at' => now(),
            ]);
        });

        if (! $run instanceof SyncRun) {
            return back()->withErrors(['command' => "A {$request->validated('command')} is already queued or running."]);
        }

        RunSyncCommand::dispatch($run);

        return redirect()->route('sync-runs.show', $run)->with('status', 'The run has been queued.');
    }
}
