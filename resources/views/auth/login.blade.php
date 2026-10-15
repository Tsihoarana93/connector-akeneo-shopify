<x-layouts.app title="Log in">
    <div class="mx-auto mt-16 w-full max-w-sm rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-800 dark:bg-gray-900">
        <h1 class="mb-6 text-lg font-semibold">Log in to Fidelity syncs</h1>

        <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-4">
            @csrf

            <label class="flex flex-col gap-1 text-sm">
                Email
                <input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username"
                       class="rounded-md border border-gray-300 px-3 py-2 dark:border-gray-700 dark:bg-gray-800">
            </label>

            <label class="flex flex-col gap-1 text-sm">
                Password
                <input type="password" name="password" required autocomplete="current-password"
                       class="rounded-md border border-gray-300 px-3 py-2 dark:border-gray-700 dark:bg-gray-800">
            </label>

            @error('email')
                <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
            @enderror

            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="remember" value="1"> Remember me
            </label>

            <button type="submit" class="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700 dark:bg-white dark:text-gray-900 dark:hover:bg-gray-200">Log in</button>
        </form>
    </div>
</x-layouts.app>
