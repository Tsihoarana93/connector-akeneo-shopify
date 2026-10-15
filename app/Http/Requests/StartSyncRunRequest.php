<?php

namespace App\Http\Requests;

use App\Models\SyncRun;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartSyncRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'command' => ['required', Rule::in(array_keys(SyncRun::COMMANDS))],
            'prune' => ['exclude_unless:command,sync', 'boolean'],
            'force' => ['exclude_unless:command,push', 'boolean'],
            'limit' => ['exclude_unless:command,push', 'nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * The full command name to run.
     */
    public function command(): string
    {
        return SyncRun::COMMANDS[$this->validated('command')];
    }

    /**
     * Options to record on the run and pass to the command, in the same shape the command records them.
     *
     * @return array{prune: bool}|array{force: bool, limit: ?int}
     */
    public function commandOptions(): array
    {
        if ($this->validated('command') === 'sync') {
            return ['prune' => $this->boolean('prune')];
        }

        $limit = $this->validated('limit');

        return ['force' => $this->boolean('force'), 'limit' => $limit !== null ? (int) $limit : null];
    }
}
