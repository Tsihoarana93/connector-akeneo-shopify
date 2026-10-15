@props(['title' => 'Syncs', 'refresh' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @if ($refresh)
        <meta http-equiv="refresh" content="{{ $refresh }}">
    @endif
    <title>{{ $title }} · {{ config('app.name') }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:300,400,500,600|space-mono:400,700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-canvas font-sans text-ink antialiased">
    <div class="flex min-h-screen flex-col lg:flex-row">
        @auth
            <aside class="flex shrink-0 flex-col border-b border-line bg-panel lg:sticky lg:top-0 lg:h-screen lg:w-64 lg:border-r lg:border-b-0">
                <a href="{{ route('sync-runs.index') }}" class="flex items-center gap-2.5 border-b border-line px-6 py-5 text-lg font-semibold tracking-tight">
                    <span class="size-2.5 rounded-[2px] bg-brand shadow-[0_0_12px] shadow-brand/70"></span>
                    Fidelity
                </a>

                <nav class="flex gap-1 overflow-x-auto px-3 py-3 lg:flex-col lg:py-6">
                    <span class="label-mono hidden px-3 pb-3 lg:block">Menu</span>
                    @foreach (['sync-runs' => 'Runs', 'attributes' => 'Attributes'] as $section => $label)
                        @php($isCurrent = request()->routeIs("{$section}.*"))
                        <a href="{{ route("{$section}.index") }}" @class([
                            'flex items-center gap-3 rounded-sm px-3 py-2 font-mono text-sm tracking-[0.08em] whitespace-nowrap uppercase transition-colors',
                            'bg-panel-raised text-ink' => $isCurrent,
                            'text-ink-subtle hover:bg-panel-raised/60 hover:text-ink' => ! $isCurrent,
                        ])>
                            <span @class(['size-1.5 rounded-[1px]', 'bg-brand shadow-[0_0_8px] shadow-brand/70' => $isCurrent, 'bg-line-strong' => ! $isCurrent])></span>
                            {{ $label }}
                        </a>
                    @endforeach
                </nav>

                <div class="flex items-center justify-between gap-3 border-t border-line px-6 py-4 lg:mt-auto lg:flex-col lg:items-stretch lg:py-5">
                    <div class="flex min-w-0 flex-col gap-1">
                        <span class="label-mono">Signed in as</span>
                        <span class="truncate text-sm text-ink-muted" title="{{ auth()->user()->email }}">{{ auth()->user()->email }}</span>
                    </div>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="btn-secondary lg:w-full">Log out</button>
                    </form>
                </div>
            </aside>
        @endauth

        <div class="flex min-w-0 flex-1 flex-col">
            @guest
                <header class="border-b border-line px-6 py-5 lg:px-10">
                    <a href="{{ route('login') }}" class="flex items-center gap-2.5 text-lg font-semibold tracking-tight">
                        <span class="size-2.5 rounded-[2px] bg-brand shadow-[0_0_12px] shadow-brand/70"></span>
                        Fidelity
                    </a>
                </header>
            @endguest

            <main class="flex flex-1 flex-col gap-8 px-6 py-10 lg:px-10">
                @if (session('status'))
                    <p class="alert-success">{{ session('status') }}</p>
                @endif

                {{ $slot }}
            </main>
        </div>
    </div>
</body>
</html>
