<x-layouts.app title="Log in">
    <div class="mx-auto mt-12 flex w-full max-w-sm flex-col gap-8">
        <div class="flex flex-col gap-3 text-center">
            <h1 class="page-title">Welcome back</h1>
            <p class="text-ink-muted">Log in to Fidelity syncs to manage the Akeneo → Shopify sync.</p>
        </div>

        <form method="POST" action="{{ route('login.store') }}" class="card flex flex-col gap-5 p-6">
            @csrf

            <label class="flex flex-col gap-2">
                <span class="label-mono">Email</span>
                <input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username" class="field">
            </label>

            <label class="flex flex-col gap-2">
                <span class="label-mono">Password</span>
                <input type="password" name="password" required autocomplete="current-password" class="field">
            </label>

            @error('email')
                <p class="alert-error">{{ $message }}</p>
            @enderror

            <label class="flex items-center gap-2 text-sm text-ink-muted">
                <input type="checkbox" name="remember" value="1" class="checkbox"> Remember me
            </label>

            <button type="submit" class="btn-primary py-2.5">Log in</button>
        </form>
    </div>
</x-layouts.app>
