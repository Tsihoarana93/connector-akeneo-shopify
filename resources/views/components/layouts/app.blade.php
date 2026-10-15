@props(['title' => 'Syncs', 'refresh' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @if ($refresh)
        <meta http-equiv="refresh" content="{{ $refresh }}">
    @endif
    <title>{{ $title }} · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-gray-50 text-gray-900 antialiased dark:bg-gray-950 dark:text-gray-100">
    @auth
        <header class="border-b border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
            <div class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-3">
                <a href="{{ route('sync-runs.index') }}" class="font-semibold">Fidelity syncs</a>
                <div class="flex items-center gap-4 text-sm">
                    <span class="text-gray-500 dark:text-gray-400">{{ auth()->user()->email }}</span>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="text-gray-700 hover:underline dark:text-gray-300">Log out</button>
                    </form>
                </div>
            </div>
        </header>
    @endauth

    <main class="mx-auto flex max-w-6xl flex-col gap-6 px-4 py-8">
        @if (session('status'))
            <p class="rounded-md bg-green-50 px-4 py-3 text-sm text-green-800 dark:bg-green-950 dark:text-green-200">{{ session('status') }}</p>
        @endif

        {{ $slot }}
    </main>
</body>
</html>
